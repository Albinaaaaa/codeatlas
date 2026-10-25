<?php

namespace App\CodeChunking;

use App\Models\CodeChunk;
use App\Models\CodeFile;
use App\Models\CodeSymbol;
use App\Models\ProjectRevision;
use App\PhpAnalysis\PhpFileInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class BuildRevisionChunks
{
    public function __construct(private readonly PhpCodeChunker $chunker) {}

    /** @return array{files: int, chunks: int, created: int, updated: int} */
    public function handle(int $revisionId): array
    {
        return DB::transaction(function () use ($revisionId): array {
            $revision = ProjectRevision::query()->lockForUpdate()->find($revisionId);
            if ($revision === null) {
                throw new RuntimeException('Project revision does not exist.');
            }
            $files = CodeFile::query()->where('project_id', $revision->project_id)
                ->where('project_revision_id', $revision->id)->where('language', 'PHP');
            $run = $revision->indexRuns()->where('project_id', $revision->project_id)->latest('id')->first();
            $statistics = $run?->getAttribute('statistics');
            if ($run === null || $run->status !== 'completed'
                || ! is_array($statistics) || ($statistics['php_files_analyzed'] ?? null) !== $files->count()) {
                throw new RuntimeException('Revision requires a completed PHP index before building chunks.');
            }
            $metadata = $revision->getAttribute('metadata');
            $snapshot = is_array($metadata) ? ($metadata['snapshot'] ?? []) : [];
            $expected = 'projects/'.$revision->project_id.'/revisions/'.$revision->id;
            if (! is_array($snapshot) || ($snapshot['disk'] ?? null) !== 'repository_snapshots'
                || ($snapshot['path'] ?? null) !== $expected) {
                throw new RuntimeException('Revision has no usable private snapshot.');
            }
            $disk = Storage::disk('repository_snapshots');
            $root = $this->inside($disk->path(''), $expected);
            if (! is_dir($root)) {
                throw new RuntimeException('Revision snapshot is unavailable.');
            }
            $result = ['files' => 0, 'chunks' => 0, 'created' => 0, 'updated' => 0];
            foreach ($files->orderBy('id')->lazyById(100) as $file) {
                $path = $this->inside($root, $file->path);
                $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
                if ($content === false || ! hash_equals($file->content_hash, hash('sha256', $content))) {
                    throw new RuntimeException('Snapshot file is unreadable or differs from its indexed hash: '.$file->path);
                }
                $symbols = CodeSymbol::query()->where('project_id', $revision->project_id)
                    ->where('project_revision_id', $revision->id)->where('code_file_id', $file->id)->get();
                $chunks = $this->chunker->chunks(new PhpFileInput((int) $file->id, $file->path, $content), $symbols);
                $scope = ['project_id' => $revision->project_id, 'project_revision_id' => $revision->id, 'code_file_id' => $file->id];
                foreach ($chunks as $index => $attributes) {
                    $chunk = CodeChunk::query()->where([...$scope, 'chunk_index' => $index])->first() ?? new CodeChunk;
                    $new = ! $chunk->exists;
                    // PostgreSQL jsonb reorders object keys; equivalent metadata must remain unchanged.
                    if (! $new && $chunk->metadata == $attributes['metadata']) {
                        unset($attributes['metadata']);
                    }
                    $chunk->forceFill([...$scope, 'chunk_index' => $index, ...$attributes]);
                    if ($chunk->isDirty()) {
                        $chunk->save();
                        $result[$new ? 'created' : 'updated']++;
                    }
                }
                CodeChunk::query()->where($scope)->where('chunk_index', '>=', count($chunks))->delete();
                $result['files']++;
                $result['chunks'] += count($chunks);
            }

            return $result;
        });
    }

    private function inside(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')
            || str_contains($relative, ':') || str_starts_with($relative, '/')
            || array_intersect(explode('/', $relative), ['', '.', '..']) !== []) {
            throw new RuntimeException('Invalid snapshot-relative path.');
        }
        $root = realpath($root);
        $candidate = $root;
        foreach (explode('/', $relative) as $segment) {
            $candidate .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($candidate)) {
                throw new RuntimeException('Snapshot paths must not contain symbolic links.');
            }
        }
        $path = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$relative);
        $prefix = $root.DIRECTORY_SEPARATOR;
        if ($path === false || ! str_starts_with(
            PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path,
            PHP_OS_FAMILY === 'Windows' ? strtolower($prefix) : $prefix,
        )) {
            throw new RuntimeException('Snapshot path is missing or outside its private root.');
        }

        return $path;
    }
}
