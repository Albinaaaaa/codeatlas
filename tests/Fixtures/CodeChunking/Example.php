<?php

namespace Fixtures\Chunks;

class Greeter
{
    public string $prefix = 'Hello';

    public function greet(): string
    {
        return $this->prefix;
    }
}

function answer(): int
{
    return 42;
}

throw new \RuntimeException('Analyzed source must never execute.');
