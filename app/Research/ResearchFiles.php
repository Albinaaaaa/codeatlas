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
        if (! preg_match('/\A[a-f0-9]{64}\z/', $experiment) || ! in_array($kind, ['grading', 'results'], true)) {
            throw new InvalidArgumentException('Invalid research export identity.');
        }
        $settings = config('filesystems.disks.thesis_research');
        $private = str_replace('\\', '/', storage_path('app/private'));
        $root = is_array($settings) ? ($settings['root'] ?? null) : null;
        if (! is_string($root) || ! str_starts_with(str_replace('\\', '/', $root), $private.'/')
            || preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $root)
            || ($settings['driver'] ?? null) !== 'local' || ($settings['serve'] ?? null) !== false
            || ($settings['visibility'] ?? null) !== 'private' || ($settings['throw'] ?? null) !== true) {
            throw new InvalidArgumentException('Research exports require non-served private local storage inside storage/app/private.');
        }
        $directory = $root.'/'.$kind.'/'.$experiment;
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
        $disk = Storage::build($settings);
        $bundle = $kind.'/'.$experiment.'/'.Str::uuid();
        $paths = [];
        foreach ($files as $name => $content) {
            if (! preg_match('/\A[a-z]+\.(?:json|jsonl|csv)\z/', $name)) {
                throw new InvalidArgumentException('Invalid research export filename.');
            }
            $disk->put($bundle.'/'.$name, $content, 'private');
            $paths[$name] = $disk->path($bundle.'/'.$name);
        }

        return $paths;
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
