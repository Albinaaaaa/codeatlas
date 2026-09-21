<?php

namespace App\PhpAnalysis;

use App\LaravelAnalysis\LaravelRouteDefinition;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;

final readonly class PhpFileAnalysis
{
    /**
     * @param  list<PhpSymbol>  $symbols
     * @param  list<PhpRelation>  $relations
     * @param  list<PhpAnalysisIssue>  $issues
     * @param  list<LaravelRouteDefinition>  $routes
     * @param  list<PhpAnalysisIssue>  $routeIssues
     * @param  list<Class_>  $modelCandidates
     * @param  array<Node>  $statements
     */
    public function __construct(
        public PhpFileInput $file,
        public array $symbols,
        public array $relations,
        public array $issues,
        public array $routes,
        public array $routeIssues,
        public array $modelCandidates = [],
        public array $statements = [],
    ) {}
}
