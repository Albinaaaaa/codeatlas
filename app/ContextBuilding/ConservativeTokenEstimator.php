<?php

namespace App\ContextBuilding;

final class ConservativeTokenEstimator
{
    public const METHOD = 'utf8-bytes-v1';

    public function estimate(string $text): int
    {
        return strlen($text);
    }
}
