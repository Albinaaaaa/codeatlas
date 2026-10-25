<?php

namespace App\Research;

use App\Inference\OllamaInference;
use App\Inference\OllamaInferenceException;
use App\Models\ThesisExperimentAttempt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RunExperiments
{
    private const VERSION = 'sequential-matrix-v1';

    private const MODELS = ['qwen2.5-coder:1.5b', 'qwen2.5-coder:7b', 'qwen2.5-coder:14b'];

    private const METHODS = ['baseline', 'keyword', 'structure_aware'];

    public function __construct(private readonly DatasetValidator $validator,
        private readonly OllamaInference $inference, private readonly RecordExperiment $records) {}

    /**
     * Limit counts actual attempts, not questions or already successful cases.
     * Callbacks receive only public progress metadata; stop is checked between cases.
     *
     * @param  callable(array<string, mixed>): void|null  $progress
     * @param  callable(): bool|null  $stop
     * @return array<string, mixed>
     */
    public function run(string $path, int $repetitions, bool $dryRun = false, ?int $limit = null,
        bool $resume = false, ?callable $progress = null, ?callable $stop = null): array
    {
        if ($repetitions < 1 || ($limit !== null && $limit < 1)) {
            throw new InvalidArgumentException('Repetitions and limit must be positive integers.');
        }
        $dataset = $this->validator->validate($path);
        if ($dataset['errors'] !== []) {
            throw new InvalidArgumentException('Dataset validation failed; use thesis:dataset:validate for line and question errors.');
        }
        $configuration = config('thesis');
        if (! is_array($configuration) || ($configuration['models'] ?? null) !== self::MODELS
            || ($configuration['methods'] ?? null) !== self::METHODS) {
            throw new InvalidArgumentException('The runner requires exactly the three configured Qwen models and retrieval methods in protocol order.');
        }
        $endpoint = config('services.ollama.url');
        $cases = [];
        $questions = [];
        foreach ($dataset['questions'] as $question) {
            $questions[$question->id] = $question;
            foreach (self::MODELS as $model) {
                foreach (self::METHODS as $method) {
                    // Preparation reuses inference validation and never contacts Ollama.
                    $prepared = $this->inference->prepare(...$question->inferenceArguments($method, $model));
                    for ($repetition = 1; $repetition <= $repetitions; $repetition++) {
                        $cases[] = ['question_id' => $question->id, 'revision_id' => $question->revisionId,
                            'revision_fingerprint' => $question->fingerprint, 'model' => $model, 'method' => $method,
                            'repetition_index' => $repetition, 'prompt_sha256' => $prepared['context']['prompt_hash']];
                    }
                }
            }
        }
        // A fixed seeded ordering avoids relying on random process state or model grouping.
        usort($cases, fn (array $a, array $b): int => strcmp(
            $this->records->identityHash(['seed' => $configuration['generation']['seed'], 'case' => $a]),
            $this->records->identityHash(['seed' => $configuration['generation']['seed'], 'case' => $b])));
        $experiment = $this->records->identityHash(['runner_version' => self::VERSION, 'dataset_sha256' => $dataset['dataset_sha256'],
            'configuration' => $configuration, 'endpoint' => $endpoint, 'repetitions' => $repetitions, 'matrix' => $cases]);
        $provenance = $dryRun ? [] : $this->provenance();
        $attempted = 0;
        $skipped = 0;
        $interrupted = false;
        foreach ($cases as &$case) {
            $modelProvenance = $provenance[$case['model']] ?? ['ollama_version' => null, 'model_digest' => null];
            $case['case_key'] = $this->records->identityHash(['experiment_key' => $experiment, 'case' => $case, 'model_provenance' => $modelProvenance]);
            $case['status'] = 'pending';
            $case['provenance_verified'] = $modelProvenance['model_digest'] !== null && $modelProvenance['ollama_version'] !== null;
            if (! $dryRun) {
                $history = ThesisExperimentAttempt::query()->where('case_key', $case['case_key'])->orderByDesc('id')->get(['status']);
                $case['status'] = $history->contains('status', 'successful') ? 'successful' : ($history->first()?->getAttribute('status') ?? 'pending');
                if ($case['status'] === 'successful' && ! $case['provenance_verified']) {
                    $case['status'] = 'pending';
                }
            }
            if ($dryRun) {
                continue;
            }
            if ($resume && $case['status'] === 'successful' && $case['provenance_verified']) {
                $skipped++;

                continue;
            }
            if (($stop !== null && $stop()) || $interrupted) {
                $interrupted = true;

                continue;
            }
            if ($limit !== null && $attempted >= $limit) {
                continue;
            }
            if (config('thesis') !== $configuration || config('services.ollama.url') !== $endpoint) {
                throw new InvalidArgumentException('Research configuration changed during execution; restart with the intended frozen configuration.');
            }
            $question = $questions[$case['question_id']];
            try {
                $outcome = $this->inference->ask(...$question->inferenceArguments($case['method'], $case['model']));
                $actualPromptHash = $outcome['prompt_hash'];
            } catch (OllamaInferenceException $error) {
                $outcome = $error;
                $actualPromptHash = $error->attemptData()['context']['prompt_hash'] ?? null;
            }
            if ($actualPromptHash !== $case['prompt_sha256']) {
                throw new InvalidArgumentException('Prepared repository context changed during execution; stop and rebuild the experiment plan.');
            }
            // Only the insert is transactional. HTTP calls above never run in a runner transaction.
            $record = $this->records->record($question, $outcome, (string) Str::uuid(), $case['repetition_index'], $modelProvenance,
                ['experiment_key' => $experiment, 'case_key' => $case['case_key'], 'runner_version' => self::VERSION,
                    'repetitions' => $repetitions, 'total_cases' => count($cases)]);
            $attempted++;
            $case['last_attempt_status'] = $record->status;
            $case['status'] = $case['status'] === 'successful' ? 'successful' : $record->status;
            $case['attempt_id'] = $record->attempt_id;
            if ($progress !== null) {
                $progress(['experiment_id' => $experiment, 'attempted' => $attempted, 'total' => count($cases), ...$case]);
            }
        }
        unset($case);
        $counts = array_count_values(array_column($cases, 'status'));
        $completed = $counts['successful'] ?? 0;

        return ['experiment_id' => $experiment, 'runner_version' => self::VERSION, 'dataset_sha256' => $dataset['dataset_sha256'],
            'protocol_version' => $configuration['protocol_version'], 'dry_run' => $dryRun, 'resume' => $resume,
            'model_provenance' => $provenance, 'case_identity_final' => ! $dryRun, 'questions' => count($questions), 'repetitions' => $repetitions,
            'total' => count($cases), 'attempted' => $attempted, 'completed' => $completed, 'failed' => $counts['failed'] ?? 0,
            'incomplete' => $counts['incomplete'] ?? 0, 'skipped' => $skipped, 'pending' => $counts['pending'] ?? 0,
            'remaining' => count($cases) - $completed, 'interrupted' => $interrupted, 'cases' => $cases];
    }

    /** @return array<string, array{ollama_version: ?string, model_digest: ?string}> */
    private function provenance(): array
    {
        $url = config('services.ollama.url');
        $client = Http::baseUrl(rtrim($url, '/'))->acceptJson()->timeout(10)->connectTimeout(3)->withoutRedirecting();
        $version = null;
        $models = [];
        try {
            $response = $client->get('/api/version');
            if ($response->successful() && is_string($response->json('version')) && trim($response->json('version')) !== '') {
                $version = $response->json('version');
            }
        } catch (ConnectionException) {
            // Unavailable metadata is null, and cannot authorize skipping a saved success.
        }
        try {
            $response = $client->get('/api/tags');
            $data = $response->json('models');
            if ($response->successful() && is_array($data)) {
                foreach ($data as $model) {
                    if (is_array($model) && in_array($model['name'] ?? null, self::MODELS, true)
                        && is_string($model['digest'] ?? null) && preg_match('/\A(?:sha256:)?[a-f0-9]{64}\z/', $model['digest'])) {
                        $models[$model['name']] = $model['digest'];
                    }
                }
            }
        } catch (ConnectionException) {
            // Model availability is still checked by the existing inference service per attempt.
        }
        $result = [];
        foreach (self::MODELS as $model) {
            $result[$model] = ['ollama_version' => $version, 'model_digest' => $models[$model] ?? null];
        }

        return $result;
    }
}
