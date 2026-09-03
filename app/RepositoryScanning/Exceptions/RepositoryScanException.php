<?php

namespace App\RepositoryScanning\Exceptions;

use RuntimeException;

class RepositoryScanException extends RuntimeException
{
    public static function sourceUnavailable(): self
    {
        return new self('The repository source is unavailable.');
    }

    public static function limitExceeded(string $limit): self
    {
        return new self("The repository exceeds the configured {$limit} limit.");
    }

    public static function preparationFailed(): self
    {
        return new self('The repository snapshot could not be prepared.');
    }

    public static function inventoryFailed(): self
    {
        return new self('The repository snapshot could not be inventoried.');
    }
}
