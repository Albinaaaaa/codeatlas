<?php

namespace App\LaravelAnalysis;

final readonly class LaravelRouteGroupContext
{
    /** @param list<string> $middleware */
    public function __construct(
        public string $uriPrefix = '',
        public string $namePrefix = '',
        public array $middleware = [],
        public ?string $controller = null,
        public bool $resolvable = true,
    ) {}
}
