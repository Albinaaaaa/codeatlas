<?php

namespace App\Jobs;

use App\Models\ResearchDataset;
use App\Models\ResearchExperiment;
use App\Models\ThesisExperimentAttempt;
use App\Research\RunExperiments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RunResearchExperimentBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public const TIMEOUT_SECONDS = 2400;

    public int $timeout = self::TIMEOUT_SECONDS;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3000;

    public function __construct(public readonly int $experimentId, public readonly string $executionIdentity,
        public readonly int $batchNumber, public readonly bool $retryFailed = false,
        public readonly ?int $retryBeforeAttemptId = null)
    {
        $this->onConnection('redis')->onQueue('research');
    }

    public function uniqueId(): string
    {
        return $this->experimentId.':'.$this->executionIdentity.':'.$this->batchNumber;
    }

    public function handle(RunExperiments $runner): void
    {
        $startedAt = microtime(true);
        $experiment = ResearchExperiment::query()->findOrFail($this->experimentId);
        $claimed = DB::transaction(function () use ($experiment): bool {
            $locked = ResearchExperiment::query()->lockForUpdate()->findOrFail($experiment->id);
            if (! hash_equals((string) $locked->execution_identity, $this->executionIdentity) || $locked->batch_number !== $this->batchNumber) {
                return false;
            }
            if ($locked->status !== 'queued') {
                return false;
            }
            $locked->forceFill(['status' => 'running', 'batch_claimed_at' => now(), 'heartbeat_at' => now(),
                'safe_error' => null, 'finished_at' => null])->save();

            return true;
        });
        if (! $claimed) {
            return;
        }
        $experiment->refresh();
        try {
            $horizonTimeout = (int) config('horizon.environments.'.app()->environment().'.supervisor-research.timeout',
                config('horizon.defaults.supervisor-research.timeout'));
            if ($horizonTimeout <= $this->timeout || (int) config('queue.connections.redis.retry_after') <= $horizonTimeout) {
                throw new RuntimeException('Research requires job timeout < Horizon timeout < Redis retry_after.');
            }
            $dataset = ResearchDataset::query()->findOrFail($experiment->research_dataset_id);
            $disk = Storage::disk('thesis_research');
            $raw = $disk->get($dataset->frozen_path);
            if ((int) $dataset->project_id !== (int) $experiment->project_id || $dataset->status !== 'frozen'
                || ! hash_equals($experiment->dataset_sha256, hash('sha256', $raw))
                || hash('sha256', json_encode(config('thesis'), JSON_THROW_ON_ERROR)) !== $experiment->configuration_identity) {
                throw new RuntimeException('Frozen experiment configuration changed.');
            }
            $retryBeforeAttemptId = $this->retryBeforeAttemptId ?? null;
            if ($this->retryFailed && $retryBeforeAttemptId === null) {
                $retryBeforeAttemptId = (int) ThesisExperimentAttempt::query()->where('project_id', $experiment->project_id)
                    ->where('experiment_key', $experiment->experiment_identity)->max('id');
            }
            $result = $runner->run($disk->path($dataset->frozen_path), $experiment->repetitions,
                limit: 1, resume: true, progress: function (): void {
                    $this->checkpoint();
                }, stop: function () use ($startedAt): bool {
                    if (! $this->currentQuery()->where('status', 'running')->exists()) {
                        return true;
                    }
                    $httpTimeout = (int) config('thesis.inference.timeout_seconds');
                    $configuredWait = config('services.ollama.lock_wait_seconds');
                    $lockWait = is_numeric($configuredWait) && (int) $configuredWait > 0 ? (int) $configuredWait : $httpTimeout + 60;
                    if (microtime(true) - $startedAt + $lockWait + $httpTimeout + min(10, $httpTimeout) + 120 >= $this->timeout) {
                        throw new RuntimeException('Research planning exhausted the safe inference time budget.');
                    }

                    return false;
                },
                expectedExperimentId: $experiment->experiment_identity,
                expectedModelProvenance: is_array($experiment->getAttribute('model_provenance')) ? $experiment->getAttribute('model_provenance') : null,
                retryFailed: $this->retryFailed, onPrepared: function (array $provenance): void {
                    if (! $this->checkpoint(['model_provenance' => $provenance])) {
                        throw new RuntimeException('Research batch execution is no longer current.');
                    }
                }, retryBeforeAttemptId: $retryBeforeAttemptId);
            DB::transaction(function () use ($result, $retryBeforeAttemptId): void {
                $current = $this->currentQuery()->where('status', 'running')->lockForUpdate()->first();
                if ($current === null) {
                    return;
                }
                $statistics = $this->statistics($current);
                $current->forceFill([...$statistics, 'skipped_cases' => $result['skipped'], 'heartbeat_at' => now(), 'safe_error' => null]);
                $retryableCases = $this->retryFailed ? $result['retryable'] : 0;
                if (($statistics['pending_cases'] > 0 || $retryableCases > 0) && $result['model_provenance_verified']) {
                    $next = $this->batchNumber + 1;
                    $current->forceFill(['batch_number' => $next, 'status' => 'queued'])->save();
                    self::dispatch($current->id, $this->executionIdentity, $next,
                        retryFailed: $this->retryFailed, retryBeforeAttemptId: $retryBeforeAttemptId)->afterCommit();
                } else {
                    $complete = $statistics['completed_cases'] === $current->total_cases && $result['model_provenance_verified'];
                    $current->forceFill(['status' => $complete ? 'completed' : 'partially_completed',
                        'safe_error' => $result['model_provenance_verified'] ? null : __('ui.research.experiment_provenance_missing'),
                        'finished_at' => now()])->save();
                }
            });
        } catch (Throwable $exception) {
            $this->checkpoint(['status' => 'partially_completed',
                'safe_error' => __('ui.research.experiment_run_failed'), 'finished_at' => now()]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->checkpoint(['status' => 'interrupted', 'safe_error' => __('ui.research.experiment_interrupted'),
            'finished_at' => now()], ['queued', 'running']);
    }

    /** @return Builder<ResearchExperiment> */
    private function currentQuery(): Builder
    {
        return ResearchExperiment::query()->whereKey($this->experimentId)->where('execution_identity', $this->executionIdentity)
            ->where('batch_number', $this->batchNumber);
    }

    /** @param array<string, mixed> $changes
     * @param  list<string>  $statuses
     */
    private function checkpoint(array $changes = [], array $statuses = ['running']): bool
    {
        return DB::transaction(function () use ($changes, $statuses): bool {
            $current = $this->currentQuery()->whereIn('status', $statuses)->lockForUpdate()->first();
            if ($current === null) {
                return false;
            }
            $current->forceFill([...$this->statistics($current), ...$changes, 'heartbeat_at' => now()])->save();

            return true;
        });
    }

    /** @return array{attempted_cases:int, completed_cases:int, failed_cases:int, incomplete_cases:int, pending_cases:int} */
    private function statistics(ResearchExperiment $experiment): array
    {
        $attempts = ThesisExperimentAttempt::query()->where('project_id', $experiment->project_id)
            ->where('experiment_key', $experiment->experiment_identity)->orderByDesc('id')->get(['id', 'case_key', 'status']);
        $states = $attempts->groupBy('case_key')->map(fn ($history): string => $history->contains('status', 'successful')
            ? 'successful' : (string) $history->first()->status);

        return ['attempted_cases' => $attempts->count(), 'completed_cases' => $states->filter(fn ($status): bool => $status === 'successful')->count(),
            'failed_cases' => $states->filter(fn ($status): bool => $status === 'failed')->count(),
            'incomplete_cases' => $states->filter(fn ($status): bool => $status === 'incomplete')->count(),
            'pending_cases' => max(0, $experiment->total_cases - $states->count())];
    }
}
