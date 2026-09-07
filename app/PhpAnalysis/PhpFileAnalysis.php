<?php

namespace App\PhpAnalysis;

use App\LaravelAnalysis\LaravelRouteDefinition;

final readonly class PhpFileAnalysis
{
    /**
     * @param  list<PhpSymbol>  $symbols
     * @param  list<PhpRelation>  $relations
     * @param  list<PhpAnalysisIssue>  $issues
     * @param  list<LaravelRouteDefinition>  $routes
     * @param  list<PhpAnalysisIssue>  $routeIssues
     */
    public function __construct(
        public PhpFileInput $file,
        public array $symbols,
        public array $relations,
        public array $issues,
        public array $routes,
        public array $routeIssues,
    ) {}
}
