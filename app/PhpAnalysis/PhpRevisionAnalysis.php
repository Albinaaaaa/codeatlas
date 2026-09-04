<?php

namespace App\PhpAnalysis;

final readonly class PhpRevisionAnalysis
{
    public function __construct(
        public int $filesAnalyzed,
        public int $symbolsPersisted,
        public int $relationsPersisted,
        public int $issuesPersisted,
    ) {}
}
