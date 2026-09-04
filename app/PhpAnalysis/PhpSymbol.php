<?php

namespace App\PhpAnalysis;

final readonly class PhpSymbol
{
    public function __construct(
        public string $key,
        public string $kind,
        public string $name,
        public string $qualifiedName,
        public ?string $parentKey,
        public int $startLine,
        public int $endLine,
        public ?string $visibility = null,
    ) {}
}
