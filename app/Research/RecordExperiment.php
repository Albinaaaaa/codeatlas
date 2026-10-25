<?php

namespace App\Research;

use App\Inference\OllamaInferenceException;
use App\Models\ProjectRevision;
use App\Models\ThesisExperimentAttempt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RecordExperiment
{
    /**
     * @param  array<string, mixed>|OllamaInferenceException  $outcome
     * @param  array<string, mixed>  $modelProvenance
     * @param  array{experiment_key: string, case_key: string, runner_version: string, repetitions: int, total_cases: int}|null  $execution
     */
    public function record(#[\SensitiveParameter] DatasetQuestion $question, #[\SensitiveParameter] array|OllamaInferenceException $outcome,
        string $attemptId, int $repetitionIndex, array $modelProvenance = [], ?array $execution = null): ThesisExperimentAttempt
    {
        if (! Str::isUuid($attemptId) || $repetitionIndex < 1) {
            throw new InvalidArgumentException('A unique attempt UUID and a positive repetition index are required.');
        }
        if ($execution !== null && (! preg_match('/\A[a-f0-9]{64}\z/', $execution['experiment_key'])
            || ! preg_match('/\A[a-f0-9]{64}\z/', $execution['case_key']) || trim($execution['runner_version']) === ''
            || $execution['repetitions'] < $repetitionIndex || $execution['total_cases'] < 1)) {
            throw new InvalidArgumentException('Execution requires SHA-256 identities and a consistent runner configuration.');
        }
        $result = $outcome instanceof OllamaInferenceException ? $this->failureResult($outcome) : $outcome;
        $revision = ProjectRevision::query()->find($question->revisionId);
        $metadata = $revision?->getAttribute('metadata');
        if ($revision === null || ! is_array($metadata)
            || ($metadata['snapshot']['fingerprint'] ?? null) !== $question->fingerprint
            || $revision->identifier !== 'snapshot:sha256:'.$question->fingerprint
            || ($result['revision_id'] ?? null) !== $question->revisionId
            || ($result['revision_identifier'] ?? null) !== $revision->identifier
            || ($result['revision_fingerprint'] ?? null) !== $question->fingerprint
            || ($result['question'] ?? null) !== $question->question) {
            throw new InvalidArgumentException('Experiment question, revision or fingerprint does not match the validated dataset.');
        }
        $prompt = $result['prompt'] ?? null;
        if (! is_string($prompt) || ($result['prompt_hash'] ?? null) !== hash('sha256', $prompt)
            || ! in_array($result['method'] ?? null, ['baseline', 'keyword', 'structure_aware'], true)
            || ! is_array($result['protocol_configuration'] ?? null)
            || ! is_array($result['generation_options'] ?? null)
            || ! is_array($result['selected_context'] ?? null) || ! is_array($result['selected_chunks'] ?? null)
            || count($result['selected_context']) !== count($result['selected_chunks'])) {
            throw new InvalidArgumentException('An intact prepared prompt, configuration and selected context are required.');
        }
        foreach ($result['selected_chunks'] as $index => $chunk) {
            $source = $result['selected_context'][$index];
            if (($chunk['revision_id'] ?? null) !== $question->revisionId
                || ! is_string($chunk['chunk_id'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $chunk['chunk_id'])
                || ($source['source'] ?? null) !== $chunk['path'].':'.$chunk['start_line'].'-'.$chunk['end_line']
                || ! is_string($source['content'] ?? null)
                || ($chunk['content_hash'] ?? null) !== hash('sha256', $source['content'])) {
                throw new InvalidArgumentException('Selected context provenance or content hash is inconsistent.');
            }
        }
        $configuration = $result['protocol_configuration'];
        if (($configuration['protocol_version'] ?? null) !== ($result['protocol_version'] ?? null)
            || ($configuration['prompt']['version'] ?? null) !== ($result['prompt_template_version'] ?? null)
            || ! is_string($result['protocol_version'] ?? null) || ! is_string($result['prompt_template_version'] ?? null)
            || ! in_array($result['requested_model'] ?? null, $configuration['models'] ?? [], true)) {
            throw new InvalidArgumentException('Experiment versions and model must match the captured protocol configuration.');
        }
        $provenance = ['ollama_version' => $modelProvenance['ollama_version'] ?? null, 'model_digest' => $modelProvenance['model_digest'] ?? null];
        foreach ($provenance as $value) {
            if ($value !== null && (! is_string($value) || trim($value) === '')) {
                throw new InvalidArgumentException('Available model provenance must be non-empty strings or null.');
            }
        }
        $status = isset($result['error']) ? ($result['completion']['status'] === 'incomplete' ? 'incomplete' : 'failed')
            : (($result['completion']['status'] ?? null) === 'complete'
                && ($result['token_comparison']['actual_prompt_plus_output_reserve_fits_num_ctx'] ?? null) !== false ? 'successful' : 'incomplete');
        $identity = ['dataset_id' => $question->datasetId, 'dataset_version' => $question->datasetVersion,
            'dataset_sha256' => $question->datasetSha256, 'split' => $question->split, 'question_id' => $question->id,
            'project_id' => (int) $revision->project_id, 'revision_id' => $question->revisionId, 'revision_fingerprint' => $question->fingerprint,
            'method' => $result['method'], 'model' => $result['requested_model'], 'prompt_sha256' => $result['prompt_hash'],
            'protocol_configuration' => $configuration, 'generation_options' => $result['generation_options'],
            'request' => $result['request'], 'model_provenance' => $provenance];
        $runKey = $this->identityHash($identity);
        try {
            return DB::transaction(fn () => ThesisExperimentAttempt::forceCreate([
                'project_id' => $revision->project_id, 'project_revision_id' => $question->revisionId, 'run_key' => $runKey,
                'experiment_key' => $execution['experiment_key'] ?? null, 'case_key' => $execution['case_key'] ?? null,
                'attempt_id' => $attemptId, 'repetition_index' => $repetitionIndex, 'dataset_id' => $question->datasetId,
                'dataset_version' => $question->datasetVersion, 'dataset_sha256' => $question->datasetSha256, 'split' => $question->split,
                'question_id' => $question->id, 'revision_fingerprint' => $question->fingerprint, 'protocol_version' => $result['protocol_version'],
                'method' => $result['method'], 'requested_model' => $result['requested_model'], 'returned_model' => $result['returned_model'] ?? null,
                'prompt_version' => $result['prompt_template_version'], 'prompt_sha256' => $result['prompt_hash'], 'status' => $status,
                'payload' => ['dataset_question' => $question->evaluationData(), 'inference' => $result, 'model_provenance' => $provenance,
                    'execution' => $execution],
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('Attempt UUID already exists; stored attempts cannot be overwritten.');
        }
    }

    /** @return array<string, mixed> */
    private function failureResult(#[\SensitiveParameter] OllamaInferenceException $error): array
    {
        $data = $error->attemptData();
        $context = $data['context'] ?? null;
        if (! is_array($context)) {
            throw new InvalidArgumentException('Failed inference has no prepared context snapshot; unavailable prompts must not be invented.');
        }
        $response = $data['response'] ?? [];
        $metrics = [];
        $unavailable = [];
        foreach (['prompt_eval_count', 'eval_count', 'total_duration', 'load_duration', 'prompt_eval_duration', 'eval_duration'] as $field) {
            $value = $response[$field] ?? null;
            $metrics[$field] = is_int($value) && $value >= 0 ? $value : null;
            if ($metrics[$field] === null) {
                $unavailable[] = $field;
            }
        }
        $answer = is_string($response['response'] ?? null) ? $response['response'] : null;
        $returned = is_string($response['model'] ?? null) ? $response['model'] : null;
        $partial = ($response['done'] ?? null) === false && $answer !== null && trim($answer) !== '' && $returned === $context['model'];

        return [...$context, 'requested_model' => $context['model'], 'returned_model' => $returned, 'answer' => $answer,
            'estimated_token_usage' => $context['token_usage'], 'actual_metrics' => $metrics, 'metrics_unavailable' => $unavailable,
            'duration_unit' => 'nanoseconds', 'generation_options' => $data['generation_options'],
            'protocol_configuration' => $data['protocol_configuration'], 'request' => $data['request'],
            'submission_attempted' => $data['submission_attempted'], 'model_metadata' => $data['model_metadata'],
            'timings_ms' => $data['timings_ms'], 'completion' => ['status' => $partial ? 'incomplete' : 'failed',
                'done' => $response['done'] ?? null, 'stop_reason' => is_string($response['done_reason'] ?? null) ? $response['done_reason'] : null],
            'error' => ['type' => $error::class, 'message' => $error->getMessage(), 'stage' => $data['stage']]];
    }

    /** @param array<string, mixed> $identity */
    public function identityHash(array $identity): string
    {
        return hash('sha256', json_encode($this->canonical($identity), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonical(mixed $value): mixed
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && $value < PHP_INT_MAX && $value > PHP_INT_MIN) {
            return (int) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonical(...), $value);
    }
}
