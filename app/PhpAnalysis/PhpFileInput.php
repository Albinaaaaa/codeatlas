<?php

namespace App\PhpAnalysis;

final readonly class PhpFileInput
{
    public function __construct(
        public int $codeFileId,
        public string $path,
        public string $contents,
    ) {}
}
