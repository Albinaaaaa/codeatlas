<?php

namespace App\Research;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ResearchFiles
{
    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * All paths are generated internally. No user-selected output destination is accepted.
     *
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    public function write(string $experiment, string $kind, #[\SensitiveParameter] array $files): array
    {
        return $this->writeBundle($experiment, $kind, $files)['files'];
    }

    /**
     * @param  array<string, string>  $files
     * @return array{bundle: string, files: array<string, string>}
     */
    public function writeBundle(string $experiment, string $kind, #[\SensitiveParameter] array $files): array
    {
        $this->assertIdentity($experiment, $kind);
        $disk = Storage::build($this->settings());
        $bundleId = (string) Str::uuid();
        $bundle = $kind.'/'.$experiment.'/'.$bundleId;
        $paths = [];
        foreach ($files as $name => $content) {
            if (! in_array($name, $this->allowedFiles($kind), true)) {
                throw new InvalidArgumentException('Invalid research export filename.');
            }
            $this->assertNoSymlinkSegments($disk->path($bundle.'/'.$name));
            $disk->put($bundle.'/'.$name, $content, 'private');
            $paths[$name] = $disk->path($bundle.'/'.$name);
        }

        return ['bundle' => $bundleId, 'files' => $paths];
    }

    /** @return list<string> */
    public function bundleFiles(string $experiment, string $kind, string $bundleId): array
    {
        $this->assertBundle($experiment, $kind, $bundleId);
        $disk = Storage::build($this->settings());

        return array_values(array_filter($this->allowedFiles($kind), fn (string $name): bool => $this->safePath($disk->path($kind.'/'.$experiment.'/'.$bundleId.'/'.$name)) !== null));
    }

    public function downloadPath(string $experiment, string $kind, string $bundleId, string $file): string
    {
        $this->assertBundle($experiment, $kind, $bundleId);
        if (! in_array($file, $this->allowedFiles($kind), true)) {
            throw new InvalidArgumentException('Research export file is not allowlisted.');
        }
        $disk = Storage::build($this->settings());
        $path = $this->safePath($disk->path($kind.'/'.$experiment.'/'.$bundleId.'/'.$file));
        if ($path === null) {
            throw new InvalidArgumentException('Research export is unavailable.');
        }

        return $path;
    }

    private function assertIdentity(string $experiment, string $kind): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $experiment) || ! in_array($kind, ['grading', 'results'], true)) {
            throw new InvalidArgumentException('Invalid research export identity.');
        }
        $this->settings();
    }

    private function assertBundle(string $experiment, string $kind, string $bundleId): void
    {
        $this->assertIdentity($experiment, $kind);
        if (! Str::isUuid($bundleId)) {
            throw new InvalidArgumentException('Invalid research export bundle.');
        }
    }

    /** @return list<string> */
    private function allowedFiles(string $kind): array
    {
        return $kind === 'results'
            ? ['raw.json', 'raw.jsonl', 'attempts.csv', 'summary.json', 'summary.csv']
            : ['answers.jsonl', 'rubric.json'];
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $settings = config('filesystems.disks.thesis_research');
        $private = str_replace('\\', '/', storage_path('app/private'));
        $root = is_array($settings) ? ($settings['root'] ?? null) : null;
        if (! is_string($root) || ! str_starts_with(str_replace('\\', '/', $root), $private.'/')
            || preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $root)
            || ($settings['driver'] ?? null) !== 'local' || ($settings['serve'] ?? null) !== false
            || ($settings['visibility'] ?? null) !== 'private' || ($settings['throw'] ?? null) !== true) {
            throw new InvalidArgumentException('Research exports require non-served private local storage inside storage/app/private.');
        }
        $directory = $root;
        while (strlen($directory) >= strlen(storage_path())) {
            if (is_link($directory)) {
                throw new InvalidArgumentException('Research export paths must not contain symbolic links.');
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return $settings;
    }

    private function assertNoSymlinkSegments(string $path): void
    {
        $root = config('filesystems.disks.thesis_research.root');
        $normalizedRoot = is_string($root) ? rtrim(str_replace('\\', '/', $root), '/') : '';
        $normalizedPath = str_replace('\\', '/', $path);
        if ($normalizedRoot === '' || ! str_starts_with($normalizedPath, $normalizedRoot.'/')) {
            throw new InvalidArgumentException('Research export path is outside private storage.');
        }
        $candidate = $normalizedRoot;
        foreach (explode('/', substr($normalizedPath, strlen($normalizedRoot) + 1)) as $segment) {
            $candidate .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($candidate)) {
                throw new InvalidArgumentException('Research export paths must not contain symbolic links.');
            }
        }
    }

    private function safePath(string $path): ?string
    {
        $root = config('filesystems.disks.thesis_research.root');
        $realRoot = is_string($root) ? realpath($root) : false;
        if ($realRoot === false || ! str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $realRoot), '/').'/')) {
            throw new InvalidArgumentException('Research export path is outside private storage.');
        }
        $relative = substr(str_replace('\\', '/', $path), strlen(rtrim(str_replace('\\', '/', $realRoot), '/')) + 1);
        $candidate = $realRoot;
        foreach (explode('/', $relative) as $segment) {
            $candidate .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($candidate)) {
                throw new InvalidArgumentException('Research export paths must not contain symbolic links.');
            }
        }
        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath)) {
            return null;
        }
        $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with(PHP_OS_FAMILY === 'Windows' ? strtolower($realPath) : $realPath,
            PHP_OS_FAMILY === 'Windows' ? strtolower($prefix) : $prefix)) {
            throw new InvalidArgumentException('Research export path is outside private storage.');
        }

        return $realPath;
    }

    /** @param list<array<string, mixed>> $rows */
    public function csv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new InvalidArgumentException('Unable to prepare CSV.');
        }
        try {
            fputcsv($stream, array_keys($rows[0]), ',', '"', '', "\r\n");
            foreach ($rows as $row) {
                $cells = array_map(function (mixed $value): string|int|float {
                    if ($value === null) {
                        return '';
                    }
                    if (is_array($value) || is_bool($value)) {
                        $value = json_encode($value, self::JSON_FLAGS);
                    }
                    // Prefix potentially executable spreadsheet text; JSON remains lossless.
                    if (is_string($value) && preg_match('/\A(?:[\p{Z}\p{C}\s]*[=+\-@]|[\t\r\n])/u', $value)) {
                        return "'".$value;
                    }

                    return is_int($value) || is_float($value) ? $value : (string) $value;
                }, array_values($row));
                fputcsv($stream, $cells, ',', '"', '', "\r\n");
            }
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}
