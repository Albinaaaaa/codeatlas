<?php

namespace App\RepositoryScanning;

final readonly class RepositoryPreparationIssue
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $title,
        public ?string $path = null,
        public ?string $description = null,
    ) {}
}
