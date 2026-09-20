<?php

namespace App\PhpAnalysis;

use App\LaravelAnalysis\LaravelRouteAnalyzer;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class PhpFileAnalyzer
{
    private readonly Parser $parser;

    public function __construct(
        private readonly LaravelRouteAnalyzer $routeAnalyzer,
    ) {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * @param  array<Node>  $statements
     * @return list<Node\Stmt\Class_>
     */
    private function modelCandidates(array $statements): array
    {
        $classes = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                array_push($classes, ...$this->modelCandidates($statement->stmts));
            } elseif ($statement instanceof Node\Stmt\Class_ && $statement->name !== null) {
                $classes[] = $statement;
            }
        }

        return $classes;
    }

    public function analyze(PhpFileInput $file): PhpFileAnalysis
    {
        try {
            $statements = $this->parser->parse($file->contents) ?? [];
            $nameTraverser = new NodeTraverser;
            $nameTraverser->addVisitor(new NameResolver);
            $statements = $nameTraverser->traverse($statements);
            $visitor = new PhpAstVisitor;
            $analysisTraverser = new NodeTraverser;
            $analysisTraverser->addVisitor($visitor);
            $analysisTraverser->traverse($statements);
            $routeAnalysis = $this->routeAnalyzer->analyze($file, $statements);

            return new PhpFileAnalysis(
                file: $file,
                symbols: $visitor->symbols(),
                relations: $visitor->relations(),
                issues: $visitor->issues(),
                routes: $routeAnalysis->routes,
                routeIssues: $routeAnalysis->issues,
                modelCandidates: $this->modelCandidates($statements),
            );
        } catch (Error $error) {
            $line = max(1, $error->getStartLine());

            return new PhpFileAnalysis(
                file: $file,
                symbols: [],
                relations: [],
                issues: [new PhpAnalysisIssue(
                    code: 'php.parse_error',
                    title: 'PHP file could not be parsed',
                    description: $error->getRawMessage(),
                    startLine: $line,
                    endLine: $line,
                )],
                routes: [],
                routeIssues: [],
            );
        }
    }
}
