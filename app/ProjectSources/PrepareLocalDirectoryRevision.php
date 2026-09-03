<?php

namespace App\ProjectSources;

use App\Enums\ProjectSourceType;
use App\Models\Project;
use App\Models\ProjectSource;
use App\RepositoryScanning\Exceptions\RepositoryScanException;
use App\RepositoryScanning\RepositorySnapshotMaterializer;
use App\RepositoryScanning\ResolvedRepositorySnapshot;

final class PrepareLocalDirectoryRevision
{
    public function __construct(
        private readonly LocalDirectorySource $localDirectory,
        private readonly RepositorySnapshotMaterializer $materializer,
    ) {}

    public function prepare(
        Project $project,
        ProjectSource $source,
    ): ResolvedRepositorySnapshot {
        if (
            (int) $source->project_id !== (int) $project->id
            || $source->type !== ProjectSourceType::LocalDirectory->value
        ) {
            throw RepositoryScanException::sourceUnavailable();
        }

        $source->loadMissing('local');
        $root = $this->localDirectory->runtimePath($source);

        if ($root === null) {
            throw RepositoryScanException::sourceUnavailable();
        }

        return $this->materializer->materialize($project, $source, $root);
    }
}
