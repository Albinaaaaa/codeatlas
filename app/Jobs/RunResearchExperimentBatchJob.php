<?php

namespace App\Jobs;

use App\Models\ResearchDataset;
use App\Models\ResearchExperiment;
use App\Research\RunExperiments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunResearchExperimentBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $experimentId, public readonly string $executionIdentity,
        public readonly int $batchNumber, public readonly bool $retryFailed = false)
    {
        $this->onConnection('redis')->onQueue('research');
    }

    public function uniqueId(): string
    {
        return $this->experimentId.':'.$this->executionIdentity.':'.$this->batchNumber;
    }

    public function handle(RunExperiments $runner): void
    {
        $experiment = ResearchExperiment::query()->findOrFail($this->experimentId);
        $claimed = DB::transaction(function () use ($experiment): bool {
            $locked = ResearchExperiment::query()->lockForUpdate()->findOrFail($experiment->id);
            if (! hash_equals((string) $locked->execution_identity, $this->executionIdentity) || $locked->batch_number !== $this->batchNumber) {
                return false;
            }
            $claim = $locked->getAttribute('batch_claimed_at');
            $stale = $locked->status === 'running' && is_string($claim) && Carbon::parse($claim)->lt(now()->subSeconds(1400));
            if ($locked->status !== 'queued' && ! $stale) {
                return false;
            }
            $locked->forceFill(['status' => 'running', 'batch_claimed_at' => now(), 'heartbeat_at' => now()])->save();

            return true;
        });
        if (! $claimed) {
            return;
        }
        try {
            $dataset = ResearchDataset::query()->findOrFail($experiment->research_dataset_id);
            $disk = Storage::disk('thesis_research');
            $raw = $disk->get($dataset->frozen_path);
            if ((int) $dataset->project_id !== (int) $experiment->project_id || $dataset->status !== 'frozen'
                || ! hash_equals($experiment->dataset_sha256, hash('sha256', $raw))
                || hash('sha256', json_encode(config('thesis'), JSON_THROW_ON_ERROR)) !== $experiment->configuration_identity) {
                throw new \RuntimeException('Frozen experiment configuration changed.');
            }
            $result = $runner->run($disk->path($dataset->frozen_path), $experiment->repetitions,
                limit: 3, resume: true, progress: function (array $progress) use ($experiment): void {
                    $current = ResearchExperiment::query()->findOrFail($experiment->id);
                    $changes = ['heartbeat_at' => now()];
                    $changes['attempted_cases'] = $current->attempted_cases + 1;
                    $status = $progress['last_attempt_status'] ?? null;
                    $previous = $progress['previous_status'] ?? null;
                    if ($status === 'successful' && $previous !== 'successful') {
                        $changes['completed_cases'] = $current->completed_cases + 1;
                    }
                    if (in_array($status, ['failed', 'incomplete'], true)) {
                        $field = $status.'_cases';
                        $changes[$field] = $current->{$field} + 1;
                    }
                    if (in_array($previous, ['failed', 'incomplete'], true)) {
                        $field = $previous.'_cases';
                        $changes[$field] = max(0, $current->{$field} - 1);
                    }
                    $changes['pending_cases'] = max(0, $current->pending_cases - 1);
                    $current->forceFill($changes)->save();
                }, expectedExperimentId: $experiment->experiment_identity,
                expectedModelProvenance: is_array($experiment->getAttribute('model_provenance')) ? $experiment->getAttribute('model_provenance') : null,
                retryFailed: $this->retryFailed);
            $experiment->forceFill(['model_provenance' => $result['model_provenance'],
                'completed_cases' => $result['completed'], 'failed_cases' => $result['failed'], 'incomplete_cases' => $result['incomplete'],
                'pending_cases' => $result['pending'], 'skipped_cases' => $result['skipped'], 'heartbeat_at' => now(), 'safe_error' => null])->save();
            if ($result['pending'] > 0 && $result['model_provenance_verified']) {
                $next = $this->batchNumber + 1;
                $experiment->forceFill(['batch_number' => $next])->save();
                self::dispatch($experiment->id, $this->executionIdentity, $next)->onConnection('redis')->onQueue('research');
            } else {
                $complete = $result['failed'] === 0 && $result['incomplete'] === 0 && $result['completed'] === $result['total']
                    && $result['model_provenance_verified'];
                $experiment->forceFill(['status' => $complete ? 'completed' : 'partially_completed',
                    'safe_error' => $result['model_provenance_verified'] ? null : __('ui.research.experiment_provenance_missing'),
                    'finished_at' => now()])->save();
            }
        } catch (Throwable) {
            $experiment->refresh()->forceFill(['status' => 'partially_completed',
                'safe_error' => __('ui.research.experiment_run_failed'), 'heartbeat_at' => now(), 'finished_at' => now()])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        ResearchExperiment::query()->whereKey($this->experimentId)->where('execution_identity', $this->executionIdentity)
            ->whereIn('status', ['queued', 'running'])->update(['status' => 'interrupted', 'safe_error' => __('ui.research.experiment_interrupted'), 'finished_at' => now()]);
    }
}
