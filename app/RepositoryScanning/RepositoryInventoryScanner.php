<?php

namespace App\RepositoryScanning;

use App\Models\CodeFile;
use App\RepositoryScanning\Exceptions\RepositoryScanException;
use Illuminate\Support\Facades\DB;

final class RepositoryInventoryScanner
{
    /**
     * @return array{file_count: int, size_bytes: int}
     */
    public function scan(ResolvedRepositorySnapshot $snapshot): array
    {
        $revision = $snapshot->revision;
        $root = realpath($snapshot->rootPath);

        if (
            ! $revision->exists
            || $root === false
            || ! is_dir($root)
            || ! is_readable($root)
        ) {
            throw RepositoryScanException::inventoryFailed();
        }

        $files = $this->inventory($root);
        $now = now();
        $rows = [];
        $totalSize = 0;

        foreach ($files as $file) {
            $contents = @file_get_contents($file['absolute']);

            if ($contents === false) {
                throw RepositoryScanException::inventoryFailed();
            }

            $size = strlen($contents);
            $totalSize += $size;
            $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
            $model = new CodeFile;
            $model->forceFill([
                'project_id' => $revision->project_id,
                'project_revision_id' => $revision->id,
                'path' => $file['path'],
                'language' => $this->language($file['path']),
                'content_hash' => hash('sha256', $contents),
                'size_bytes' => $size,
                'line_count' => $this->lineCount($contents),
                'is_generated' => false,
                'metadata' => [
                    'extension' => $extension === '' ? null : $extension,
                    'content_type' => 'text',
                ],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $rows[] = $model->getAttributes();
        }

        DB::transaction(function () use ($revision, $rows): void {
            $query = CodeFile::query()
                ->where('project_id', $revision->project_id)
                ->where('project_revision_id', $revision->id);
            $paths = array_column($rows, 'path');

            if ($paths === []) {
                $query->delete();
            } else {
                $query->whereNotIn('path', $paths)->delete();
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                CodeFile::query()->upsert(
                    $chunk,
                    ['project_id', 'project_revision_id', 'path'],
                    [
                        'language',
                        'content_hash',
                        'size_bytes',
                        'line_count',
                        'is_generated',
                        'metadata',
                        'updated_at',
                    ],
                );
            }
        });

        return [
            'file_count' => count($rows),
            'size_bytes' => $totalSize,
        ];
    }

    /** @return list<array{path: string, absolute: string}> */
    private function inventory(string $root): array
    {
        $directories = [['path' => '', 'absolute' => $root]];
        $files = [];

        while ($directory = array_pop($directories)) {
            $names = @scandir($directory['absolute']);

            if ($names === false) {
                throw RepositoryScanException::inventoryFailed();
            }

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $relativePath = $directory['path'] === ''
                    ? $name
                    : $directory['path'].'/'.$name;
                $absolutePath = $directory['absolute'].DIRECTORY_SEPARATOR.$name;

                if (! $this->isValidRelativePath($relativePath) || is_link($absolutePath)) {
                    throw RepositoryScanException::inventoryFailed();
                }

                $canonicalPath = realpath($absolutePath);

                if ($canonicalPath === false || ! $this->isInside($canonicalPath, $root)) {
                    throw RepositoryScanException::inventoryFailed();
                }

                if (is_dir($canonicalPath)) {
                    $directories[] = [
                        'path' => $relativePath,
                        'absolute' => $canonicalPath,
                    ];
                } elseif (is_file($canonicalPath)) {
                    $files[] = [
                        'path' => $relativePath,
                        'absolute' => $canonicalPath,
                    ];
                } else {
                    throw RepositoryScanException::inventoryFailed();
                }
            }
        }

        usort(
            $files,
            fn (array $left, array $right): int => strcmp($left['path'], $right['path']),
        );

        return $files;
    }

    private function lineCount(string $contents): int
    {
        if ($contents === '') {
            return 0;
        }

        return substr_count($contents, "\n") + (str_ends_with($contents, "\n") ? 0 : 1);
    }

    private function language(string $path): ?string
    {
        $basename = strtolower(basename($path));

        if (str_ends_with($basename, '.blade.php')) {
            return 'Blade';
        }

        return match (strtolower(pathinfo($basename, PATHINFO_EXTENSION))) {
            'php' => 'PHP',
            'js', 'jsx', 'mjs', 'cjs' => 'JavaScript',
            'ts', 'tsx', 'mts', 'cts' => 'TypeScript',
            'css', 'scss', 'sass', 'less' => 'CSS',
            'html', 'htm' => 'HTML',
            'json', 'jsonc' => 'JSON',
            'md', 'mdx' => 'Markdown',
            'xml' => 'XML',
            'yml', 'yaml' => 'YAML',
            'sql' => 'SQL',
            'sh', 'bash', 'zsh' => 'Shell',
            'py' => 'Python',
            default => null,
        };
    }

    private function isValidRelativePath(string $path): bool
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/^[a-z]:/i', $path) === 1
            || ! mb_check_encoding($path, 'UTF-8')
        ) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function isInside(string $path, string $root): bool
    {
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);

        if (PHP_OS_FAMILY === 'Windows') {
            $path = mb_strtolower($path);
            $root = mb_strtolower($root);
        }

        return $path !== $root && str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }
}
