<?php

namespace App\RepositoryScanning;

final class RepositoryFilePolicy
{
    private const EXCLUDED_DIRECTORIES = [
        '.git',
        'vendor',
        'node_modules',
        'storage',
    ];

    public function excludesDirectory(string $relativePath): bool
    {
        $segments = array_map('strtolower', explode('/', $relativePath));

        foreach ($segments as $segment) {
            if (in_array($segment, self::EXCLUDED_DIRECTORIES, true)) {
                return true;
            }
        }

        $count = count($segments);

        for ($index = 0; $index < $count - 1; $index++) {
            if ($segments[$index] === 'bootstrap' && $segments[$index + 1] === 'cache') {
                return true;
            }
        }

        return false;
    }

    public function excludesFile(string $relativePath): bool
    {
        if ($this->excludesDirectory($relativePath)) {
            return true;
        }

        $basename = strtolower(basename($relativePath));

        if ($basename === '.env' || str_starts_with($basename, '.env.')) {
            return true;
        }

        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        return in_array($extension, ['pem', 'key'], true);
    }
}
