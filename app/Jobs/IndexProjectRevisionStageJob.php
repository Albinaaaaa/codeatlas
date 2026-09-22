<?php

namespace App\Jobs;

use App\Actions\Projects\RunProjectIndexingStage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class IndexProjectRevisionStageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $revisionId,
        public int $indexRunId,
        public string $stage,
    ) {}

    public function handle(RunProjectIndexingStage $runner): void
    {
        $runner->handle($this->revisionId, $this->indexRunId, $this->stage);
    }

    public function uniqueId(): string
    {
        return $this->revisionId.':'.$this->indexRunId.':'.$this->stage;
    }
}
