<?php

namespace App\LaravelAnalysis;

final readonly class LaravelRouteDefinition
{
    /** @param list<string> $middleware */
    public function __construct(
        public string $method,
        public string $uri,
        public ?string $name,
        public ?string $controller,
        public ?string $controllerMethod,
        public array $middleware,
        public int $startLine,
        public int $endLine,
    ) {}
}
