<?php

namespace App\Actions\Projects;

use App\Models\AnalysisIssue;
use App\Models\IndexRun;
use App\Models\IndexRunStep;
use App\Models\Project;
use App\Models\ProjectSource;
use App\ProjectSources\LocalDirectorySource;
use App\ProjectSources\PrepareLocalDirectoryRevision;
use App\RepositoryScanning\Exceptions\RepositoryScanException;
use App\RepositoryScanning\RepositoryInventoryScanner;
use App\RepositoryScanning\RepositoryPreparationIssue;
use Throwable;

final class ScanProjectRepository
{
    public function __construct(
        private readonly LocalDirectorySource $localDirectory,
        private readonly PrepareLocalDirectoryRevision $prepareLocalDirectory,
        private readonly RepositoryInventoryScanner $scanner,
    ) {}

    public function assertSourceAvailable(Project $project): void
    {
        $this->sourceFor($project);
    }

    public function handle(Project $project): IndexRun
    {
        $source = $this->sourceFor($project);

        $snapshot = $this->prepareLocalDirectory->prepare($project, $source);
        $run = new IndexRun([
            'status' => 'pending',
            'trigger' => 'manual',
        ]);
        $run->project()->associate($project);
        $snapshot->revision->indexRuns()->save($run);

        $step = new IndexRunStep([
            'name' => 'repository_inventory',
            'status' => 'pending',
        ]);
        $step->project()->associate($project);
        $step->revision()->associate($snapshot->revision);
        $run->steps()->save($step);

        $startedAt = now();
        $run->update([
            'status' => 'running',
            'started_at' => $startedAt,
        ]);
        $step->update([
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        try {
            foreach ($snapshot->issues as $issue) {
                $this->persistIssue($project, $run, $issue);
            }

            $statistics = $this->scanner->scan($snapshot);
            $completedAt = now();
            $step->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'records_processed' => $statistics['file_count'],
                'metadata' => [
                    'size_bytes' => $statistics['size_bytes'],
                ],
            ]);
            $run->update([
                'status' => 'completed',
                'completed_at' => $completedAt,
                'statistics' => [
                    'files_discovered' => $statistics['file_count'],
                    'size_bytes' => $statistics['size_bytes'],
                    'issues_count' => count($snapshot->issues),
                    'snapshot_fingerprint' => $snapshot->fingerprint,
                    'revision_created' => $snapshot->revisionCreated,
                ],
            ]);

            return $run->refresh()->load('revision');
        } catch (Throwable $exception) {
            $failedAt = now();
            $reason = $exception instanceof RepositoryScanException
                ? $exception->getMessage()
                : 'The repository scan failed unexpectedly.';
            $step->update([
                'status' => 'failed',
                'completed_at' => $failedAt,
                'failure_reason' => $reason,
            ]);
            $run->update([
                'status' => 'failed',
                'completed_at' => $failedAt,
                'failure_reason' => $reason,
            ]);

            if ($exception instanceof RepositoryScanException) {
                throw $exception;
            }

            throw new RepositoryScanException(
                'The repository scan failed unexpectedly.',
                previous: $exception,
            );
        }
    }

    private function persistIssue(
        Project $project,
        IndexRun $run,
        RepositoryPreparationIssue $issue,
    ): void {
        $model = new AnalysisIssue([
            'severity' => $issue->severity,
            'category' => 'repository_preparation',
            'code' => $issue->code,
            'title' => $issue->title,
            'description' => $issue->description,
            'source_path' => $issue->path,
        ]);
        $model->project()->associate($project);
        $model->revision()->associate($run->revision);
        $model->run()->associate($run);
        $model->save();
    }

    private function sourceFor(Project $project): ProjectSource
    {
        $source = $this->localDirectory->findFor($project);

        if ($source === null || ! $this->localDirectory->isAvailable($source)) {
            throw RepositoryScanException::sourceUnavailable();
        }

        return $source;
    }
}
