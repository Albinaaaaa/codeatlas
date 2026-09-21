<?php

namespace App\PhpAnalysis;

use App\LaravelAnalysis\LaravelAsyncAnalyzer;
use App\LaravelAnalysis\LaravelMigrationAnalyzer;
use App\LaravelAnalysis\LaravelModelAnalyzer;
use App\Models\AnalysisIssue;
use App\Models\CodeRelation;
use App\Models\CodeSymbol;
use App\Models\IndexRun;
use App\Models\LaravelRoute;
use App\Models\ProjectRevision;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PhpRevisionAnalyzer
{
    public function __construct(
        private readonly PhpFileAnalyzer $fileAnalyzer,
        private readonly LaravelModelAnalyzer $modelAnalyzer,
        private readonly LaravelAsyncAnalyzer $asyncAnalyzer,
        private readonly LaravelMigrationAnalyzer $migrationAnalyzer,
    ) {}

    public function analyze(
        ProjectRevision $revision,
        string $snapshotRoot,
        ?IndexRun $run = null,
    ): PhpRevisionAnalysis {
        if (! $revision->exists) {
            throw new InvalidArgumentException('The project revision must be persisted.');
        }

        if ($run !== null && (
            (int) $run->project_id !== (int) $revision->project_id
            || (int) $run->project_revision_id !== (int) $revision->id
        )) {
            throw new InvalidArgumentException('The index run must belong to the analyzed revision.');
        }

        $root = realpath($snapshotRoot);

        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            throw new InvalidArgumentException('The revision snapshot must be a readable directory.');
        }

        $analyses = [];
        $files = $revision->codeFiles()
            ->where('language', 'PHP')
            ->orderBy('path')
            ->get();

        foreach ($files as $file) {
            $absolutePath = $this->snapshotFile($root, $file->path);
            $contents = $absolutePath === null ? false : @file_get_contents($absolutePath);
            $input = new PhpFileInput(
                codeFileId: (int) $file->id,
                path: $file->path,
                contents: $contents === false ? '' : $contents,
            );

            if ($contents === false) {
                $analyses[] = new PhpFileAnalysis(
                    file: $input,
                    symbols: [],
                    relations: [],
                    issues: [new PhpAnalysisIssue(
                        code: 'php.unreadable_file',
                        title: 'PHP file could not be read from the revision snapshot',
                    )],
                    routes: [],
                    routeIssues: [],
                );

                continue;
            }

            $analyses[] = $this->fileAnalyzer->analyze($input);
        }

        return DB::transaction(function () use ($revision, $run, $analyses): PhpRevisionAnalysis {
            $projectId = (int) $revision->project_id;
            $revisionId = (int) $revision->id;

            DB::table('analysis_issues')
                ->where('project_id', $projectId)
                ->where('project_revision_id', $revisionId)
                ->whereIn('category', ['php_ast', 'laravel_route', 'laravel_model'])
                ->delete();
            DB::table('laravel_routes')
                ->where('project_id', $projectId)
                ->where('project_revision_id', $revisionId)
                ->delete();
            DB::table('code_relations')
                ->where('project_id', $projectId)
                ->where('project_revision_id', $revisionId)
                ->delete();
            DB::table('code_symbols')
                ->where('project_id', $projectId)
                ->where('project_revision_id', $revisionId)
                ->delete();

            /** @var array<string, int> $symbolIds */
            $symbolIds = [];
            /** @var array<string, int> $qualifiedSymbolIds */
            $qualifiedSymbolIds = [];
            $symbolCount = 0;
            $relationCount = 0;
            $routeCount = 0;
            $issueCount = 0;

            foreach ($analyses as $analysis) {
                foreach ($analysis->symbols as $symbol) {
                    $localKey = $analysis->file->codeFileId.':'.$symbol->key;
                    $parentId = $symbol->parentKey === null
                        ? null
                        : $symbolIds[$analysis->file->codeFileId.':'.$symbol->parentKey];
                    $record = new CodeSymbol([
                        'kind' => $symbol->kind,
                        'name' => $symbol->name,
                        'qualified_name' => $symbol->qualifiedName,
                        'visibility' => $symbol->visibility,
                        'start_line' => $symbol->startLine,
                        'end_line' => $symbol->endLine,
                    ]);
                    $record->project()->associate($projectId);
                    $record->revision()->associate($revision);
                    $record->codeFile()->associate($analysis->file->codeFileId);
                    $record->parentSymbol()->associate($parentId);
                    $record->save();
                    $symbolId = (int) $record->id;
                    $symbolIds[$localKey] = $symbolId;
                    $qualifiedSymbolIds[$symbol->qualifiedName] ??= $symbolId;
                    $symbolCount++;
                }
            }

            foreach ($analyses as $analysis) {
                foreach ($analysis->relations as $relation) {
                    $record = new CodeRelation([
                        'type' => $relation->type,
                        'target_name' => $relation->targetName,
                        'start_line' => $relation->startLine,
                        'end_line' => $relation->endLine,
                    ]);
                    $record->project()->associate($projectId);
                    $record->revision()->associate($revision);
                    $record->codeFile()->associate($analysis->file->codeFileId);
                    $record->fromSymbol()->associate($symbolIds[$analysis->file->codeFileId.':'.$relation->fromSymbolKey]);
                    $record->toSymbol()->associate($qualifiedSymbolIds[$relation->targetName] ?? null);
                    $record->save();
                    $relationCount++;
                }

                foreach ($analysis->routes as $route) {
                    $controllerSymbolId = $route->controller === null
                        ? null
                        : ($qualifiedSymbolIds[$route->controller] ?? null);
                    $methodSymbolId = $route->controller === null || $route->controllerMethod === null
                        ? null
                        : ($qualifiedSymbolIds[$route->controller.'::'.$route->controllerMethod] ?? null);
                    $action = $route->controller === null
                        ? 'Closure'
                        : $route->controller.($route->controllerMethod === null ? '' : '@'.$route->controllerMethod);

                    $record = new LaravelRoute([
                        'method' => $route->method,
                        'uri' => $route->uri,
                        'name' => $route->name,
                        'action' => $action,
                        'middleware' => $route->middleware,
                        'start_line' => $route->startLine,
                        'end_line' => $route->endLine,
                        'metadata' => [
                            'controller_method' => $route->controllerMethod,
                            'controller_method_symbol_id' => $methodSymbolId,
                        ],
                    ]);
                    $record->project()->associate($projectId);
                    $record->revision()->associate($revision);
                    $record->codeFile()->associate($analysis->file->codeFileId);
                    $record->controllerSymbol()->associate($controllerSymbolId);
                    $record->save();
                    $routeCount++;
                }

                foreach ($analysis->issues as $issue) {
                    $record = new AnalysisIssue([
                        'severity' => $issue->severity,
                        'category' => 'php_ast',
                        'code' => $issue->code,
                        'title' => $issue->title,
                        'description' => $issue->description,
                        'source_path' => $analysis->file->path,
                        'start_line' => $issue->startLine,
                        'end_line' => $issue->endLine,
                    ]);
                    $record->project()->associate($projectId);
                    $record->revision()->associate($revision);
                    $record->run()->associate($run);
                    $record->codeFile()->associate($analysis->file->codeFileId);
                    $record->save();
                    $issueCount++;
                }

                foreach ($analysis->routeIssues as $issue) {
                    $record = new AnalysisIssue([
                        'severity' => $issue->severity,
                        'category' => 'laravel_route',
                        'code' => $issue->code,
                        'title' => $issue->title,
                        'description' => $issue->description,
                        'source_path' => $analysis->file->path,
                        'start_line' => $issue->startLine,
                        'end_line' => $issue->endLine,
                    ]);
                    $record->project()->associate($projectId);
                    $record->revision()->associate($revision);
                    $record->run()->associate($run);
                    $record->codeFile()->associate($analysis->file->codeFileId);
                    $record->save();
                    $issueCount++;
                }
            }

            $modelAnalysis = $this->modelAnalyzer->persist($revision, $analyses, $qualifiedSymbolIds, $run);
            $relationCount += $this->asyncAnalyzer->persist($revision, $analyses, $qualifiedSymbolIds);
            $migrationAnalysis = $this->migrationAnalyzer->persist($revision, $analyses, $run);

            return new PhpRevisionAnalysis(
                filesAnalyzed: count($analyses),
                symbolsPersisted: $symbolCount,
                relationsPersisted: $relationCount,
                routesPersisted: $routeCount,
                issuesPersisted: $issueCount + $modelAnalysis['issues'],
                modelsPersisted: $modelAnalysis['models'],
                modelRelationsPersisted: $modelAnalysis['relations'],
                databaseTablesPersisted: $migrationAnalysis['tables'],
                databaseColumnsPersisted: $migrationAnalysis['columns'],
                databaseIndexesPersisted: $migrationAnalysis['indexes'],
                databaseForeignKeysPersisted: $migrationAnalysis['foreign_keys'],
                migrationIssuesPersisted: $migrationAnalysis['issues'],
            );
        });
    }

    private function snapshotFile(string $root, string $relativePath): ?string
    {
        if (
            $relativePath === ''
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')
            || str_starts_with($relativePath, '/')
        ) {
            return null;
        }

        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        if ($path === false || ! is_file($path)) {
            return null;
        }

        $normalizedPath = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $normalizedRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);

        if (PHP_OS_FAMILY === 'Windows') {
            $normalizedPath = mb_strtolower($normalizedPath);
            $normalizedRoot = mb_strtolower($normalizedRoot);
        }

        return str_starts_with($normalizedPath, $normalizedRoot.DIRECTORY_SEPARATOR) ? $path : null;
    }
}
