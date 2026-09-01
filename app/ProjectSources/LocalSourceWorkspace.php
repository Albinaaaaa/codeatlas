<?php

namespace App\ProjectSources;

final class LocalSourceWorkspace
{
    public function isEnabled(): bool
    {
        return config('codeatlas.local_source_enabled') === true;
    }

    public function isConfigured(): bool
    {
        return $this->rootPath() !== null;
    }

    public function rootPath(): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $configuredRoot = config('codeatlas.local_source_root');

        if (! is_string($configuredRoot)) {
            return null;
        }

        $configuredRoot = trim($configuredRoot);

        if ($configuredRoot === '' || ! $this->isAbsolutePath($configuredRoot)) {
            return null;
        }

        $canonicalRoot = realpath($configuredRoot);

        if ($canonicalRoot === false || ! is_dir($canonicalRoot) || ! is_readable($canonicalRoot)) {
            return null;
        }

        return $canonicalRoot;
    }

    public function canonicalRelativeDirectory(string $path): ?string
    {
        return $this->resolve($path)['relative'] ?? null;
    }

    public function resolveDirectory(string $path): ?string
    {
        return $this->resolve($path)['absolute'] ?? null;
    }

    public function displayPath(string $path): ?string
    {
        return $this->normalizeRelativePath($path);
    }

    /**
     * @return array{relative: string, absolute: string}|null
     */
    private function resolve(string $path): ?array
    {
        $relativePath = $this->normalizeRelativePath($path);
        $rootPath = $this->rootPath();

        if ($relativePath === null || $rootPath === null) {
            return null;
        }

        $candidatePath = $rootPath.DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath,
        );
        $canonicalPath = realpath($candidatePath);

        if ($canonicalPath === false || ! is_dir($canonicalPath) || ! is_readable($canonicalPath)) {
            return null;
        }

        if (! $this->isInsideRoot($canonicalPath, $rootPath)) {
            return null;
        }

        return [
            'relative' => $this->relativeToRoot($canonicalPath, $rootPath),
            'absolute' => $canonicalPath,
        ];
    }

    private function normalizeRelativePath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, "\0") || $this->isAbsolutePath($path)) {
            return null;
        }

        $path = str_replace('\\', '/', $path);
        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return implode('/', $segments);
    }

    private function isAbsolutePath(string $path): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);

        return str_starts_with($normalizedPath, '/')
            || preg_match('/^[a-z]:/i', $normalizedPath) === 1;
    }

    private function isInsideRoot(string $path, string $root): bool
    {
        $comparisonPath = $this->comparisonPath($path);
        $comparisonRoot = $this->comparisonPath($root);
        $rootPrefix = $comparisonRoot === DIRECTORY_SEPARATOR
            ? DIRECTORY_SEPARATOR
            : $comparisonRoot.DIRECTORY_SEPARATOR;

        return $comparisonPath !== $comparisonRoot
            && str_starts_with($comparisonPath, $rootPrefix);
    }

    private function relativeToRoot(string $path, string $root): string
    {
        $relativePath = ltrim(
            substr($path, strlen(rtrim($root, '/\\'))),
            '/\\',
        );

        return str_replace('\\', '/', $relativePath);
    }

    private function comparisonPath(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if ($path === '') {
            return DIRECTORY_SEPARATOR;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $path = mb_strtolower($path);
        }

        return $path;
    }
}
