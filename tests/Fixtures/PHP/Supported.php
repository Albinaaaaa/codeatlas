<?php

namespace Fixtures\Domain;

use Countable as Counter;

interface Contract {}

interface ExtendedContract extends Contract {}

trait Logs {}

class BaseService {}

enum Status implements Contract
{
    case Active;

    public const LABEL = 'active';

    public function label(): string
    {
        return self::LABEL;
    }
}

final class Service extends BaseService implements Contract, Counter
{
    use Logs;

    public const VERSION = 1;

    protected string $name = 'service';

    public function __construct(private readonly int $id) {}

    private function work(): void {}

    public function count(): int
    {
        return $this->id;
    }
}
