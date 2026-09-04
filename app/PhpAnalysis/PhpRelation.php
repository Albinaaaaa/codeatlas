<?php

namespace App\PhpAnalysis;

final readonly class PhpRelation
{
    public function __construct(
        public string $fromSymbolKey,
        public string $type,
        public string $targetName,
        public int $startLine,
        public int $endLine,
    ) {}
}
