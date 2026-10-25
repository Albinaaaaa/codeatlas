<?php

namespace App\Inference;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class OllamaInferenceException extends RuntimeException implements ShouldntReport
{
    /** @param array<string, mixed> $attempt */
    public function __construct(string $message, #[\SensitiveParameter] private readonly array $attempt = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function attemptData(): array
    {
        return $this->attempt;
    }
}
