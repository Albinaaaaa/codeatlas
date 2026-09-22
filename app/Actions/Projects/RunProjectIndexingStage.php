<?php

namespace App\Actions\Projects;

use App\Jobs\IndexProjectRevisionStageJob;
use App\Models\IndexRun;
use App\Models\ProjectRevision;
use App\PhpAnalysis\PhpRevisionAnalyzer;
use App\RepositoryScanning\RepositoryInventoryScanner;
use App\RepositoryScanning\ResolvedRepositorySnapshot;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class RunProjectIndexingStage
{
    /** @var list<string> */
    private const STAGES = ['scan', 'php_analysis', 'laravel_analysis', 'database_analysis', 'relation_resolution', 'persistence', 'project_profile', 'finalize'];

    public function __construct(
        private readonly RepositoryInventoryScanner $scanner,
        private readonly PhpRevisionAnalyzer $phpAnalyzer,
    ) {}

    public function handle(int $revisionId, int $runId, string $stage): void
    {
        if (! in_array($stage, self::STAGES, true)) {
            throw new RuntimeException('Unknown indexing stage.');
        }

        $revision = ProjectRevision::query()->findOrFail($revisionId);
        $run = IndexRun::query()->whereKey($runId)->where('project_id', $revision->project_id)->where('project_revision_id', $revision->id)->firstOrFail();
        $step = $run->steps()->where('name', $stage)->firstOrFail();
        $step->update(['status' => 'running', 'started_at' => now(), 'failure_reason' => null]);

        try {
            $snapshot = $this->snapshot($revision);
            $metadata = [];
            $records = 0;
            if ($stage === 'scan') {
                $stats = $this->scanner->scan($snapshot);
                $records = (int) $stats['file_count'];
                $metadata = $stats;
                $this->mergeStatistics($run, ['files_discovered' => $records, 'size_bytes' => $stats['size_bytes'], 'snapshot_fingerprint' => $snapshot->fingerprint]);
            } elseif ($stage === 'php_analysis') {
                $analysis = $this->phpAnalyzer->analyze($revision, $snapshot->rootPath, $run);
                $records = $analysis->filesAnalyzed;
                $metadata = ['php_files_analyzed' => $analysis->filesAnalyzed, 'php_symbols' => $analysis->symbolsPersisted, 'php_relations' => $analysis->relationsPersisted, 'issues_count' => $analysis->issuesPersisted, 'laravel_routes' => $analysis->routesPersisted, 'laravel_models' => $analysis->modelsPersisted, 'laravel_model_relations' => $analysis->modelRelationsPersisted, 'database_tables' => $analysis->databaseTablesPersisted, 'database_columns' => $analysis->databaseColumnsPersisted, 'database_indexes' => $analysis->databaseIndexesPersisted, 'database_foreign_keys' => $analysis->databaseForeignKeysPersisted];
                $this->mergeStatistics($run, $metadata);
            } else {
                $metadata = ['message' => 'Completed as part of the deterministic revision analysis.'];
            }
            $step->update(['status' => 'completed', 'completed_at' => now(), 'records_processed' => $records, 'metadata' => $metadata]);
            $next = array_search($stage, self::STAGES, true) + 1;
            if ($next < count(self::STAGES)) {
                $nextStage = self::STAGES[$next];
                IndexProjectRevisionStageJob::dispatch($revision->id, $run->id, $nextStage)->onQueue('indexing');
            } else {
                $run->update(['status' => 'completed', 'completed_at' => now()]);
            }
        } catch (Throwable $exception) {
            $reason = $exception->getMessage() !== '' ? $exception->getMessage() : 'Indexing stage failed.';
            $step->update(['status' => 'failed', 'completed_at' => now(), 'failure_reason' => $reason]);
            $run->update(['status' => 'failed', 'completed_at' => now(), 'failure_reason' => $reason]);
            throw $exception;
        }
    }

    private function snapshot(ProjectRevision $revision): ResolvedRepositorySnapshot
    {
        $metadataValue = $revision->getAttribute('metadata');
        $metadata = is_array($metadataValue) ? $metadataValue : [];
        $snapshotValue = $metadata['snapshot'] ?? null;
        $snapshot = is_array($snapshotValue) ? $snapshotValue : [];
        $disk = is_string($snapshot['disk'] ?? null) ? $snapshot['disk'] : 'repository_snapshots';
        $path = is_string($snapshot['path'] ?? null) ? $snapshot['path'] : null;
        $root = $path === null ? null : realpath(Storage::disk($disk)->path($path));
        if ($root === false || $root === null || ! is_dir($root)) {
            throw new RuntimeException('The revision snapshot is unavailable.');
        }

        return new ResolvedRepositorySnapshot($revision, $root, (string) ($snapshot['fingerprint'] ?? ''), false, []);
    }

    /** @param array<string,mixed> $values */
    private function mergeStatistics(IndexRun $run, array $values): void
    {
        $current = $run->getAttribute('statistics');
        $run->update(['statistics' => array_merge(is_array($current) ? $current : [], $values)]);
    }
}
