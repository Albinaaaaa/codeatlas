<?php

namespace App\Jobs;

use App\CodeChunking\BuildRevisionChunks;
use App\Inference\OllamaInference;
use App\Inference\OllamaInferenceException;
use App\Models\ProjectRevision;
use App\Models\RepositoryQuestion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class AnswerRepositoryQuestionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;

    public int $timeout = 720;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 720;

    public function __construct(public readonly int $questionId)
    {
        $this->onConnection('redis')->onQueue('ask');
    }

    public function uniqueId(): string
    {
        return (string) $this->questionId;
    }

    public function handle(BuildRevisionChunks $chunks, OllamaInference $inference): void
    {
        $claimed = DB::transaction(function (): bool {
            $question = RepositoryQuestion::query()->lockForUpdate()->findOrFail($this->questionId);
            if ($question->status !== 'queued') {
                return false;
            }
            $question->forceFill(['status' => 'preparing', 'started_at' => now(), 'heartbeat_at' => now(), 'safe_error' => null])->save();

            return true;
        });
        if (! $claimed) {
            return;
        }

        $question = RepositoryQuestion::query()->findOrFail($this->questionId);
        try {
            $revision = ProjectRevision::query()->where('project_id', $question->project_id)->findOrFail($question->project_revision_id);
            $metadata = $revision->getAttribute('metadata');
            $snapshot = is_array($metadata) ? ($metadata['snapshot'] ?? null) : null;
            $fingerprint = is_array($snapshot) ? ($snapshot['fingerprint'] ?? null) : null;
            if (! is_string($fingerprint) || ! hash_equals($question->revision_fingerprint, $fingerprint)
                || $revision->identifier !== 'snapshot:sha256:'.$fingerprint
                || ($snapshot['disk'] ?? null) !== 'repository_snapshots') {
                throw new \RuntimeException('Revision snapshot identity is unavailable.');
            }

            // This service reads and verifies the immutable snapshot; it never scans the live project source.
            $chunks->handle($revision->id);
            $question->forceFill(['status' => 'running', 'heartbeat_at' => now()])->save();
            $result = $inference->ask($revision->id, $question->question, $question->retrieval_method,
                $question->requested_model, (int) config('services.ollama.interactive_lock_wait_seconds', 15));
            if (($result['revision_fingerprint'] ?? null) !== $question->revision_fingerprint
                || ($result['revision_id'] ?? null) !== $revision->id
                || ($result['question'] ?? null) !== $question->question) {
                throw new \RuntimeException('Inference provenance did not match the selected repository revision.');
            }

            $completion = $result['completion'] ?? [];
            $metrics = $result['actual_metrics'] ?? [];
            $selectedChunks = $this->safeChunks($result['selected_chunks'] ?? []);
            $context = $this->safeContext($result['selected_context'] ?? [], $selectedChunks);
            $status = ($completion['status'] ?? null) === 'complete' ? 'completed' : 'incomplete';
            $question->forceFill([
                'answer' => $result['answer'],
                'source_context' => $context,
                'source_chunks' => $selectedChunks,
                'prompt_sha256' => $result['prompt_hash'],
                'estimated_prompt_tokens' => $result['estimated_token_usage']['prompt_tokens'] ?? null,
                'actual_input_tokens' => $metrics['prompt_eval_count'] ?? null,
                'actual_output_tokens' => $metrics['eval_count'] ?? null,
                'inference_duration_ns' => $metrics['total_duration'] ?? null,
                'inference_wall_ms' => $result['timings_ms']['inference_wall'] ?? null,
                'completion_status' => $completion['status'] ?? 'unknown',
                'stop_reason' => $completion['stop_reason'] ?? null,
                'status' => $status,
                'safe_error' => $status === 'completed' ? null : 'The model stopped before completing its answer.',
                'heartbeat_at' => now(),
                'completed_at' => now(),
            ])->save();
        } catch (LockTimeoutException) {
            $question->forceFill(['status' => 'queued', 'started_at' => null, 'heartbeat_at' => now()])->save();
            if ($this->attempts() < 8) {
                $this->release(30);

                return;
            }
            $question->forceFill(['status' => 'incomplete', 'safe_error' => 'Another inference is still running. Submit the question again later.',
                'completed_at' => now()])->save();
        } catch (OllamaInferenceException $exception) {
            $attempt = $exception->attemptData();
            $context = $attempt['context'] ?? [];
            $submitted = ($attempt['submission_attempted'] ?? false) === true;
            $question->forceFill([
                'source_context' => is_array($context) ? $this->safeContext($context['selected_context'] ?? [],
                    $this->safeChunks($context['selected_chunks'] ?? [])) : [],
                'source_chunks' => is_array($context) ? $this->safeChunks($context['selected_chunks'] ?? []) : [],
                'prompt_sha256' => is_array($context) ? ($context['prompt_hash'] ?? null) : null,
                'estimated_prompt_tokens' => is_array($context) ? ($context['token_usage']['prompt_tokens'] ?? null) : null,
                'status' => $submitted ? 'incomplete' : 'failed',
                'completion_status' => $submitted ? 'incomplete' : 'failed',
                'safe_error' => $this->safeInferenceError($exception->getMessage()),
                'heartbeat_at' => now(),
                'completed_at' => now(),
            ])->save();
        } catch (Throwable) {
            $question->forceFill(['status' => 'failed', 'safe_error' => 'The repository snapshot or inference service could not complete this question.',
                'heartbeat_at' => now(), 'completed_at' => now()])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        RepositoryQuestion::query()->whereKey($this->questionId)->whereIn('status', ['queued', 'preparing', 'running'])
            ->update(['status' => 'incomplete', 'safe_error' => 'The background worker stopped before the answer was saved.',
                'completed_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function safeChunks(mixed $chunks): array
    {
        if (! is_array($chunks)) {
            return [];
        }

        $safe = [];
        foreach ($chunks as $chunk) {
            if (! is_array($chunk) || ! is_string($chunk['path'] ?? null)
                || preg_match('/\A(?:[A-Za-z]:|[\/\\\\])/', $chunk['path']) === 1
                || str_contains($chunk['path'], "\0")
                || array_intersect(explode('/', $chunk['path']), ['', '.', '..']) !== []
                || ! is_int($chunk['start_line'] ?? null) || ! is_int($chunk['end_line'] ?? null)
                || $chunk['start_line'] < 1 || $chunk['end_line'] < $chunk['start_line']) {
                continue;
            }
            $safe[] = array_intersect_key($chunk, array_flip([
                'chunk_id', 'revision_id', 'revision_identifier', 'path', 'start_line', 'end_line', 'rank', 'score',
                'reason', 'selection_reason', 'content_hash',
            ]));
        }

        return $safe;
    }

    /**
     * @param  list<array<string, mixed>>  $chunks
     * @return list<array{source: string, content: string}>
     */
    private function safeContext(mixed $context, array $chunks): array
    {
        if (! is_array($context)) {
            return [];
        }
        $allowed = [];
        foreach ($chunks as $chunk) {
            $allowed[$chunk['path'].':'.$chunk['start_line'].'-'.$chunk['end_line']] = true;
        }
        $sources = [];
        foreach ($context as $source) {
            if (is_array($source) && is_string($source['source'] ?? null) && isset($allowed[$source['source']])
                && is_string($source['content'] ?? null)) {
                $sources[] = ['source' => $source['source'], 'content' => $source['content']];
            }
        }

        return $sources;
    }

    private function safeInferenceError(string $message): string
    {
        return match (true) {
            str_contains($message, 'connection failure or timeout') => 'Ollama is unavailable or the inference request timed out.',
            str_contains($message, 'empty answer') => 'Ollama returned an empty answer.',
            str_contains($message, 'Incomplete') => 'Ollama returned an incomplete answer.',
            default => 'Ollama could not complete the inference request.',
        };
    }
}
