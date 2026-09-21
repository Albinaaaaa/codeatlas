<?php

namespace App\PhpAnalysis;

final readonly class PhpRevisionAnalysis
{
    public function __construct(
        public int $filesAnalyzed,
        public int $symbolsPersisted,
        public int $relationsPersisted,
        public int $routesPersisted,
        public int $issuesPersisted,
        public int $modelsPersisted = 0,
        public int $modelRelationsPersisted = 0,
        public int $databaseTablesPersisted = 0,
        public int $databaseColumnsPersisted = 0,
        public int $databaseIndexesPersisted = 0,
        public int $databaseForeignKeysPersisted = 0,
        public int $migrationIssuesPersisted = 0,
    ) {}
}
