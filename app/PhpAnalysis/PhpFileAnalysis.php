<?php

namespace App\PhpAnalysis;

final readonly class PhpFileAnalysis
{
    /**
     * @param  list<PhpSymbol>  $symbols
     * @param  list<PhpRelation>  $relations
     * @param  list<PhpAnalysisIssue>  $issues
     */
    public function __construct(
        public PhpFileInput $file,
        public array $symbols,
        public array $relations,
        public array $issues,
    ) {}
}
