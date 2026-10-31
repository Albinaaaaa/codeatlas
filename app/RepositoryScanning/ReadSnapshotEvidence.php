<?php

namespace App\RepositoryScanning;

use App\Models\CodeFile;
use App\Models\ProjectRevision;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

final class ReadSnapshotEvidence
{
    public function root(ProjectRevision $revision, string $fingerprint): string
    {
        $metadata = $revision->getAttribute('metadata');
        $snapshot = is_array($metadata) ? ($metadata['snapshot'] ?? null) : null;
        if (! is_array($snapshot)
            || ($snapshot['fingerprint'] ?? null) !== $fingerprint
            || ($snapshot['fingerprint_algorithm'] ?? null) !== 'sha256' || ($snapshot['format_version'] ?? null) !== 1
            || $revision->identifier !== 'snapshot:sha256:'.$fingerprint
            || ($snapshot['disk'] ?? null) !== 'repository_snapshots'
            || ($snapshot['path'] ?? null) !== 'projects/'.$revision->project_id.'/revisions/'.$revision->id) {
            throw new InvalidArgumentException('Revision fingerprint or private snapshot metadata does not match.');
        }

        return $this->inside(Storage::disk('repository_snapshots')->path(''), $snapshot['path']);
    }

    public function content(ProjectRevision $revision, string $root, string $path): string
    {
        $absolute = $this->inside($root, $path);
        $file = CodeFile::query()->where('project_id', $revision->project_id)->where('project_revision_id', $revision->id)
            ->where('path', $path)->first();
        $content = is_file($absolute) && is_readable($absolute) ? file_get_contents($absolute) : false;
        if ($file === null || $content === false || ! hash_equals($file->content_hash, hash('sha256', $content))) {
            throw new InvalidArgumentException('Evidence file is missing, unindexed or differs from its indexed hash.');
        }

        return $content;
    }

    /** @return array{path: string, start_line: int, end_line: int, lines: list<string>} */
    public function read(ProjectRevision $revision, string $fingerprint, string $path, int $start, int $end): array
    {
        if ($start < 1 || $end < $start || $end - $start >= 500) {
            throw new InvalidArgumentException('Invalid evidence range (maximum 500 lines).');
        }
        $content = $this->content($revision, $this->root($revision, $fingerprint), $path);
        $lines = $content === '' ? [] : preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false || ! mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidArgumentException('Evidence must contain readable UTF-8 text.');
        }
        if (str_ends_with($content, "\n") || str_ends_with($content, "\r")) {
            array_pop($lines);
        }
        if ($end > count($lines)) {
            throw new InvalidArgumentException('Evidence line range exceeds the snapshot file.');
        }

        return ['path' => $path, 'start_line' => $start, 'end_line' => $end, 'lines' => array_slice($lines, $start - 1, $end - $start + 1)];
    }

    private function inside(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\') || str_contains($relative, ':')
            || str_starts_with($relative, '/') || array_intersect(explode('/', $relative), ['', '.', '..']) !== []) {
            throw new InvalidArgumentException('Evidence must use a safe snapshot-relative path.');
        }
        $root = realpath($root);
        if ($root === false) {
            throw new InvalidArgumentException('Private snapshot root is unavailable.');
        }
        $candidate = $root;
        foreach (explode('/', $relative) as $segment) {
            $candidate .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($candidate)) {
                throw new InvalidArgumentException('Snapshot evidence must not use symbolic links.');
            }
        }
        $path = realpath($candidate);
        $prefix = $root.DIRECTORY_SEPARATOR;
        if ($path === false || ! str_starts_with(PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path,
            PHP_OS_FAMILY === 'Windows' ? strtolower($prefix) : $prefix)) {
            throw new InvalidArgumentException('Evidence path is missing or outside the private snapshot.');
        }

        return $path;
    }
}
