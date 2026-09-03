<?php

namespace App\RepositoryScanning;

use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\ProjectSource;
use App\RepositoryScanning\Exceptions\RepositoryScanException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RepositorySnapshotMaterializer
{
    private const SNAPSHOT_FORMAT_VERSION = 1;

    public function __construct(
        private readonly RepositoryFilePolicy $policy,
        private readonly Filesystem $files,
    ) {}

    public function materialize(
        Project $project,
        ProjectSource $source,
        string $resolvedRoot,
    ): ResolvedRepositorySnapshot {
        if ((int) $source->project_id !== (int) $project->id) {
            throw RepositoryScanException::sourceUnavailable();
        }

        $sourceRoot = realpath($resolvedRoot);

        if ($sourceRoot === false || ! is_dir($sourceRoot) || ! is_readable($sourceRoot)) {
            throw RepositoryScanException::sourceUnavailable();
        }

        $storageRoot = $this->storageRoot();

        if ($this->pathsOverlap($sourceRoot, $storageRoot)) {
            throw RepositoryScanException::preparationFailed();
        }

        $issues = [];
        $stagingRoot = $storageRoot.DIRECTORY_SEPARATOR.'.staging'.DIRECTORY_SEPARATOR.bin2hex(random_bytes(16));
        $this->files->ensureDirectoryExists($stagingRoot, 0700, true);

        try {
            $candidates = $this->collectCandidates($sourceRoot, $issues);
            $manifest = $this->copyCandidates(
                $sourceRoot,
                $stagingRoot,
                $candidates,
                $issues,
                $this->maxFileSize($project),
            );
            $fingerprint = $this->fingerprint($manifest);
            [$revision, $revisionCreated, $snapshotRoot] = $this->storeRevision(
                $project,
                $source,
                $stagingRoot,
                $storageRoot,
                $fingerprint,
                $manifest,
            );

            return new ResolvedRepositorySnapshot(
                revision: $revision,
                rootPath: $snapshotRoot,
                fingerprint: $fingerprint,
                revisionCreated: $revisionCreated,
                issues: $issues,
            );
        } catch (RepositoryScanException $exception) {
            $this->files->deleteDirectory($stagingRoot);

            throw $exception;
        } catch (Throwable $exception) {
            $this->files->deleteDirectory($stagingRoot);

            throw new RepositoryScanException(
                'The repository snapshot could not be prepared.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  list<RepositoryPreparationIssue>  $issues
     * @return list<array{path: string, absolute: string}>
     */
    private function collectCandidates(string $sourceRoot, array &$issues): array
    {
        $directories = [['path' => '', 'absolute' => $sourceRoot]];
        $candidates = [];
        $entryCount = 0;
        $fileCount = 0;
        $maxEntries = $this->positiveConfig('max_entries');
        $maxFiles = $this->positiveConfig('max_files');

        while ($directory = array_pop($directories)) {
            $names = @scandir($directory['absolute']);

            if ($names === false) {
                $issues[] = new RepositoryPreparationIssue(
                    severity: 'warning',
                    code: 'repository.unreadable_directory',
                    title: 'Directory could not be read',
                    path: $directory['path'] ?: null,
                );

                continue;
            }

            sort($names, SORT_STRING);

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $entryCount++;

                if ($entryCount > $maxEntries) {
                    throw RepositoryScanException::limitExceeded('entry count');
                }

                $relativePath = $directory['path'] === ''
                    ? $name
                    : $directory['path'].'/'.$name;

                if (! $this->isValidRelativePath($relativePath)) {
                    $issues[] = new RepositoryPreparationIssue(
                        severity: 'warning',
                        code: 'repository.invalid_path',
                        title: 'Invalid repository path was skipped',
                    );

                    continue;
                }

                $absolutePath = $directory['absolute'].DIRECTORY_SEPARATOR.$name;

                if (is_link($absolutePath)) {
                    $issues[] = $this->symlinkIssue($absolutePath, $relativePath, $sourceRoot);

                    continue;
                }

                if (is_dir($absolutePath)) {
                    if ($this->policy->excludesDirectory($relativePath)) {
                        continue;
                    }

                    $canonicalDirectory = realpath($absolutePath);

                    if ($canonicalDirectory === false || ! $this->isInside($canonicalDirectory, $sourceRoot)) {
                        $issues[] = new RepositoryPreparationIssue(
                            severity: 'warning',
                            code: 'repository.path_escape',
                            title: 'Directory outside the repository was skipped',
                            path: $relativePath,
                        );

                        continue;
                    }

                    $directories[] = [
                        'path' => $relativePath,
                        'absolute' => $canonicalDirectory,
                    ];

                    continue;
                }

                if (! is_file($absolutePath)) {
                    $issues[] = new RepositoryPreparationIssue(
                        severity: 'info',
                        code: 'repository.unsupported_entry',
                        title: 'Unsupported repository entry was skipped',
                        path: $relativePath,
                    );

                    continue;
                }

                $fileCount++;

                if ($fileCount > $maxFiles) {
                    throw RepositoryScanException::limitExceeded('file count');
                }

                if ($this->policy->excludesFile($relativePath)) {
                    continue;
                }

                $candidates[] = [
                    'path' => $relativePath,
                    'absolute' => $absolutePath,
                ];
            }
        }

        usort(
            $candidates,
            fn (array $left, array $right): int => strcmp($left['path'], $right['path']),
        );

        return $candidates;
    }

    /**
     * @param  list<array{path: string, absolute: string}>  $candidates
     * @param  list<RepositoryPreparationIssue>  $issues
     * @return list<array{path: string, hash: string, size: int}>
     */
    private function copyCandidates(
        string $sourceRoot,
        string $stagingRoot,
        array $candidates,
        array &$issues,
        int $maxFileSize,
    ): array {
        $manifest = [];
        $totalSize = 0;
        $maxTotalSize = $this->positiveConfig('max_total_size');

        foreach ($candidates as $candidate) {
            $file = $this->readStableFile(
                $candidate['absolute'],
                $candidate['path'],
                $sourceRoot,
                $maxFileSize,
                $issues,
            );

            if ($file === null) {
                continue;
            }

            $totalSize += $file['size'];

            if ($totalSize > $maxTotalSize) {
                throw RepositoryScanException::limitExceeded('total size');
            }

            $targetPath = $stagingRoot.DIRECTORY_SEPARATOR.str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $candidate['path'],
            );
            $this->files->ensureDirectoryExists(dirname($targetPath), 0700, true);

            if ($this->files->put($targetPath, $file['contents'], true) === false) {
                throw RepositoryScanException::preparationFailed();
            }

            @chmod($targetPath, 0600);
            $manifest[] = [
                'path' => $candidate['path'],
                'hash' => hash('sha256', $file['contents']),
                'size' => $file['size'],
            ];
        }

        return $manifest;
    }

    /**
     * @param  list<RepositoryPreparationIssue>  $issues
     * @return array{contents: string, size: int}|null
     */
    private function readStableFile(
        string $path,
        string $relativePath,
        string $sourceRoot,
        int $maxFileSize,
        array &$issues,
    ): ?array {
        if (is_link($path)) {
            $issues[] = $this->symlinkIssue($path, $relativePath, $sourceRoot);

            return null;
        }

        $canonicalPath = realpath($path);

        if ($canonicalPath === false || ! $this->isInside($canonicalPath, $sourceRoot)) {
            $issues[] = new RepositoryPreparationIssue(
                severity: 'warning',
                code: 'repository.path_escape',
                title: 'File outside the repository was skipped',
                path: $relativePath,
            );

            return null;
        }

        $handle = @fopen($canonicalPath, 'rb');

        if ($handle === false) {
            $issues[] = new RepositoryPreparationIssue(
                severity: 'warning',
                code: 'repository.unreadable_file',
                title: 'File could not be read',
                path: $relativePath,
            );

            return null;
        }

        try {
            $before = fstat($handle);

            if ($before === false || $before['size'] < 0) {
                $issues[] = new RepositoryPreparationIssue(
                    severity: 'warning',
                    code: 'repository.invalid_file',
                    title: 'Invalid file was skipped',
                    path: $relativePath,
                );

                return null;
            }

            if ($before['size'] > $maxFileSize) {
                $issues[] = new RepositoryPreparationIssue(
                    severity: 'warning',
                    code: 'repository.file_too_large',
                    title: 'Oversized file was skipped',
                    path: $relativePath,
                    description: "The file exceeds the {$maxFileSize} byte limit.",
                );

                return null;
            }

            $contents = stream_get_contents($handle, min($maxFileSize, PHP_INT_MAX - 1) + 1);
            $after = fstat($handle);

            if (
                $contents === false
                || $after === false
                || strlen($contents) !== (int) $before['size']
                || $before['size'] !== $after['size']
                || $before['mtime'] !== $after['mtime']
            ) {
                $issues[] = new RepositoryPreparationIssue(
                    severity: 'warning',
                    code: 'repository.file_changed',
                    title: 'File changed while being read and was skipped',
                    path: $relativePath,
                );

                return null;
            }

            if (
                str_contains(substr($contents, 0, 8192), "\0")
                || ! mb_check_encoding($contents, 'UTF-8')
            ) {
                $issues[] = new RepositoryPreparationIssue(
                    severity: 'info',
                    code: 'repository.binary_file',
                    title: 'Binary file was skipped',
                    path: $relativePath,
                );

                return null;
            }

            return [
                'contents' => $contents,
                'size' => strlen($contents),
            ];
        } finally {
            fclose($handle);
        }
    }

    private function symlinkIssue(
        string $path,
        string $relativePath,
        string $sourceRoot,
    ): RepositoryPreparationIssue {
        $target = realpath($path);
        $escapes = $target !== false && ! $this->isInside($target, $sourceRoot);

        return new RepositoryPreparationIssue(
            severity: 'warning',
            code: $escapes ? 'repository.symlink_escape' : 'repository.symlink_skipped',
            title: $escapes
                ? 'Symlink outside the repository was skipped'
                : 'Symlink was skipped',
            path: $relativePath,
        );
    }

    /**
     * @param  list<array{path: string, hash: string, size: int}>  $manifest
     */
    private function fingerprint(array $manifest): string
    {
        $context = hash_init('sha256');
        hash_update($context, 'codeatlas-repository-snapshot-v'.self::SNAPSHOT_FORMAT_VERSION."\0");

        foreach ($manifest as $file) {
            hash_update(
                $context,
                strlen($file['path']).':'.$file['path']."\0"
                .$file['hash']."\0"
                .$file['size']."\0",
            );
        }

        return hash_final($context);
    }

    /**
     * @param  list<array{path: string, hash: string, size: int}>  $manifest
     * @return array{ProjectRevision, bool, string}
     */
    private function storeRevision(
        Project $project,
        ProjectSource $source,
        string $stagingRoot,
        string $storageRoot,
        string $fingerprint,
        array $manifest,
    ): array {
        $revisionCreated = false;

        $revision = DB::transaction(function () use (
            $project,
            $source,
            $fingerprint,
            &$revisionCreated,
        ): ProjectRevision {
            $identifier = 'snapshot:sha256:'.$fingerprint;
            ProjectSource::query()
                ->whereKey($source->id)
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->firstOrFail();
            $revision = ProjectRevision::query()
                ->where('project_id', $project->id)
                ->where('project_source_id', $source->id)
                ->where('identifier', $identifier)
                ->lockForUpdate()
                ->first();

            if ($revision !== null) {
                return $revision;
            }

            $revision = new ProjectRevision(['identifier' => $identifier]);
            $revision->project()->associate($project);
            $source->revisions()->save($revision);
            $revisionCreated = true;

            return $revision;
        });

        $snapshotPath = 'projects/'.$project->id.'/revisions/'.$revision->id;
        $snapshotRoot = $storageRoot.DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $snapshotPath,
        );

        if (is_dir($snapshotRoot)) {
            $this->files->deleteDirectory($stagingRoot);
        } else {
            $this->files->ensureDirectoryExists(dirname($snapshotRoot), 0700, true);

            if (! @rename($stagingRoot, $snapshotRoot)) {
                throw RepositoryScanException::preparationFailed();
            }
        }

        $metadataValue = $revision->getAttribute('metadata');
        $metadata = is_array($metadataValue) ? $metadataValue : [];
        $snapshotMetadata = $metadata['snapshot'] ?? null;
        $existingPreparedAt = is_array($snapshotMetadata)
            ? ($snapshotMetadata['prepared_at'] ?? null)
            : null;
        $metadata['snapshot'] = [
            'disk' => 'repository_snapshots',
            'path' => $snapshotPath,
            'format_version' => self::SNAPSHOT_FORMAT_VERSION,
            'fingerprint_algorithm' => 'sha256',
            'fingerprint' => $fingerprint,
            'file_count' => count($manifest),
            'size_bytes' => array_sum(array_column($manifest, 'size')),
            'prepared_at' => is_string($existingPreparedAt)
                ? $existingPreparedAt
                : now()->toISOString(),
        ];
        $revision->setAttribute('metadata', $metadata);

        if ($revision->isDirty('metadata')) {
            $revision->save();
        }

        $canonicalSnapshotRoot = realpath($snapshotRoot);

        if ($canonicalSnapshotRoot === false || ! is_dir($canonicalSnapshotRoot)) {
            throw RepositoryScanException::preparationFailed();
        }

        return [$revision, $revisionCreated, $canonicalSnapshotRoot];
    }

    private function storageRoot(): string
    {
        $configuredRoot = config('filesystems.disks.repository_snapshots.root');

        if (! is_string($configuredRoot) || trim($configuredRoot) === '') {
            throw RepositoryScanException::preparationFailed();
        }

        $this->files->ensureDirectoryExists($configuredRoot, 0700, true);
        $canonicalRoot = realpath($configuredRoot);

        if ($canonicalRoot === false || ! is_dir($canonicalRoot) || ! is_writable($canonicalRoot)) {
            throw RepositoryScanException::preparationFailed();
        }

        return $canonicalRoot;
    }

    private function maxFileSize(Project $project): int
    {
        $projectLimit = $project->settings()->value('max_file_size');

        return is_numeric($projectLimit)
            ? max(1, (int) $projectLimit)
            : $this->positiveConfig('max_file_size');
    }

    private function positiveConfig(string $key): int
    {
        $value = config("codeatlas.repository_scan.{$key}");

        return max(1, is_numeric($value) ? (int) $value : 1);
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

    private function pathsOverlap(string $left, string $right): bool
    {
        $left = $this->comparisonPath($left);
        $right = $this->comparisonPath($right);

        return $left === $right
            || str_starts_with($left, $right.DIRECTORY_SEPARATOR)
            || str_starts_with($right, $left.DIRECTORY_SEPARATOR);
    }

    private function isInside(string $path, string $root): bool
    {
        $path = $this->comparisonPath($path);
        $root = $this->comparisonPath($root);

        return $path !== $root && str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    private function comparisonPath(string $path): string
    {
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        if (PHP_OS_FAMILY === 'Windows') {
            return mb_strtolower($path);
        }

        return $path;
    }
}
