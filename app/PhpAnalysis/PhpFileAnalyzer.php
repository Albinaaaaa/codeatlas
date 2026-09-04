<?php

namespace App\PhpAnalysis;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class PhpFileAnalyzer
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
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

            return new PhpFileAnalysis(
                file: $file,
                symbols: $visitor->symbols(),
                relations: $visitor->relations(),
                issues: $visitor->issues(),
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
            );
        }
    }
}
