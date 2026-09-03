<?php

namespace App\RepositoryScanning;

use App\Models\ProjectRevision;

final readonly class ResolvedRepositorySnapshot
{
    /**
     * @param  list<RepositoryPreparationIssue>  $issues
     */
    public function __construct(
        public ProjectRevision $revision,
        public string $rootPath,
        public string $fingerprint,
        public bool $revisionCreated,
        public array $issues,
    ) {}
}
