<?php

namespace App\Jobs;

use App\Models\ResearchExperiment;
use App\Research\PrepareResearchExperiment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PrepareResearchExperimentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $experimentId)
    {
        $this->onConnection('redis')->onQueue('research')->afterCommit();
    }

    public function handle(PrepareResearchExperiment $prepare): void
    {
        $experiment = ResearchExperiment::query()->findOrFail($this->experimentId);
        try {
            $prepare->handle($experiment);
        } catch (Throwable) {
            $experiment->refresh()->forceFill(['status' => 'failed', 'safe_error' => __('ui.research.experiment_prepare_failed'),
                'finished_at' => now(), 'heartbeat_at' => now()])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        ResearchExperiment::query()->whereKey($this->experimentId)->where('status', 'preparing')
            ->update(['status' => 'failed', 'safe_error' => __('ui.research.experiment_prepare_failed'), 'finished_at' => now(), 'heartbeat_at' => now()]);
    }
}
