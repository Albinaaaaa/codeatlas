<?php

namespace App\Jobs;

use App\Actions\Projects\ScanProjectRepository;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScanProjectRepositoryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public int $projectId) {}

    public function handle(ScanProjectRepository $scanRepository): void
    {
        $project = Project::query()->find($this->projectId);

        if ($project === null) {
            return;
        }

        $scanRepository->handle($project);
    }

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }
}
