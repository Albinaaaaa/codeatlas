<?php

namespace App\LaravelAnalysis;

use App\PhpAnalysis\PhpAnalysisIssue;

final readonly class LaravelRouteFileAnalysis
{
    /**
     * @param  list<LaravelRouteDefinition>  $routes
     * @param  list<PhpAnalysisIssue>  $issues
     */
    public function __construct(
        public array $routes,
        public array $issues,
    ) {}
}
