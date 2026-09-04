<?php

namespace App\PhpAnalysis;

final readonly class PhpAnalysisIssue
{
    public function __construct(
        public string $code,
        public string $title,
        public ?string $description = null,
        public ?int $startLine = null,
        public ?int $endLine = null,
        public string $severity = 'warning',
    ) {}
}
