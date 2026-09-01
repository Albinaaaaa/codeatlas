<?php

namespace App\ProjectSources;

use FilesystemIterator;
use InvalidArgumentException;
use UnexpectedValueException;

final class LocalDirectoryBrowser
{
    public function __construct(
        private readonly LocalSourceWorkspace $workspace,
    ) {}

    /**
     * @return array{
     *     current_path: string,
     *     current_display_path: string,
     *     parent_path: string|null,
     *     directories: list<array{name: string, path: string}>
     * }
     */
    public function browse(?string $path = null): array
    {
        $rootPath = $this->workspace->rootPath();
        $currentRelativePath = $path === null || $path === ''
            ? ''
            : $this->workspace->canonicalRelativeDirectory($path);

        if ($rootPath === null) {
            throw new InvalidArgumentException('The local source workspace is not configured.');
        }

        if ($currentRelativePath === null) {
            throw new InvalidArgumentException('The requested directory is outside the configured roots.');
        }

        $currentPath = $currentRelativePath === ''
            ? $rootPath
            : $this->workspace->resolveDirectory($currentRelativePath);

        if ($currentPath === null) {
            throw new InvalidArgumentException('The requested directory cannot be opened.');
        }

        $directories = [];

        try {
            $iterator = new FilesystemIterator(
                $currentPath,
                FilesystemIterator::SKIP_DOTS,
            );

            foreach ($iterator as $entry) {
                if (! $entry->isDir() || str_starts_with($entry->getFilename(), '.')) {
                    continue;
                }

                $candidateRelativePath = $currentRelativePath === ''
                    ? $entry->getFilename()
                    : $currentRelativePath.'/'.$entry->getFilename();
                $childPath = $this->workspace->canonicalRelativeDirectory($candidateRelativePath);

                if ($childPath === null) {
                    continue;
                }

                $directories[$childPath] = [
                    'name' => $entry->getFilename(),
                    'path' => $childPath,
                ];
            }
        } catch (UnexpectedValueException) {
            throw new InvalidArgumentException('The requested directory cannot be opened.');
        }

        usort(
            $directories,
            fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']),
        );

        return [
            'current_path' => $currentRelativePath,
            'current_display_path' => $currentRelativePath,
            'parent_path' => $currentRelativePath === ''
                ? null
                : $this->parentPath($currentRelativePath),
            'directories' => $directories,
        ];
    }

    public function isConfigured(): bool
    {
        return $this->workspace->isConfigured();
    }

    public function isEnabled(): bool
    {
        return $this->workspace->isEnabled();
    }

    private function parentPath(string $path): string
    {
        $separatorPosition = strrpos($path, '/');

        return $separatorPosition === false
            ? ''
            : substr($path, 0, $separatorPosition);
    }
}
