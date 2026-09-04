<?php

namespace App\PhpAnalysis;

use App\Models\IndexRun;
use App\Models\ProjectRevision;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PhpRevisionAnalyzer
{
    public function __construct(
        private readonly PhpFileAnalyzer $fileAnalyzer,
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
                );

                continue;
            }

            $analyses[] = $this->fileAnalyzer->analyze($input);
        }

        return DB::transaction(function () use ($revision, $run, $analyses): PhpRevisionAnalysis {
            $projectId = (int) $revision->project_id;
            $revisionId = (int) $revision->id;
            $now = now();

            DB::table('analysis_issues')
                ->where('project_id', $projectId)
                ->where('project_revision_id', $revisionId)
                ->where('category', 'php_ast')
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
            $issueCount = 0;

            foreach ($analyses as $analysis) {
                foreach ($analysis->symbols as $symbol) {
                    $localKey = $analysis->file->codeFileId.':'.$symbol->key;
                    $parentId = $symbol->parentKey === null
                        ? null
                        : $symbolIds[$analysis->file->codeFileId.':'.$symbol->parentKey];
                    $symbolId = DB::table('code_symbols')->insertGetId([
                        'project_id' => $projectId,
                        'project_revision_id' => $revisionId,
                        'code_file_id' => $analysis->file->codeFileId,
                        'parent_symbol_id' => $parentId,
                        'kind' => $symbol->kind,
                        'name' => $symbol->name,
                        'qualified_name' => $symbol->qualifiedName,
                        'visibility' => $symbol->visibility,
                        'start_line' => $symbol->startLine,
                        'end_line' => $symbol->endLine,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $symbolIds[$localKey] = $symbolId;
                    $qualifiedSymbolIds[$symbol->qualifiedName] ??= $symbolId;
                    $symbolCount++;
                }
            }

            foreach ($analyses as $analysis) {
                foreach ($analysis->relations as $relation) {
                    DB::table('code_relations')->insert([
                        'project_id' => $projectId,
                        'project_revision_id' => $revisionId,
                        'from_symbol_id' => $symbolIds[$analysis->file->codeFileId.':'.$relation->fromSymbolKey],
                        'to_symbol_id' => $qualifiedSymbolIds[$relation->targetName] ?? null,
                        'code_file_id' => $analysis->file->codeFileId,
                        'type' => $relation->type,
                        'target_name' => $relation->targetName,
                        'start_line' => $relation->startLine,
                        'end_line' => $relation->endLine,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $relationCount++;
                }

                foreach ($analysis->issues as $issue) {
                    DB::table('analysis_issues')->insert([
                        'project_id' => $projectId,
                        'project_revision_id' => $revisionId,
                        'index_run_id' => $run?->id,
                        'code_file_id' => $analysis->file->codeFileId,
                        'severity' => $issue->severity,
                        'category' => 'php_ast',
                        'code' => $issue->code,
                        'title' => $issue->title,
                        'description' => $issue->description,
                        'source_path' => $analysis->file->path,
                        'start_line' => $issue->startLine,
                        'end_line' => $issue->endLine,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $issueCount++;
                }
            }

            return new PhpRevisionAnalysis(
                filesAnalyzed: count($analyses),
                symbolsPersisted: $symbolCount,
                relationsPersisted: $relationCount,
                issuesPersisted: $issueCount,
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
