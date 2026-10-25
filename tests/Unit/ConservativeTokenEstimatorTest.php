<?php

namespace Tests\Unit;

use App\ContextBuilding\ConservativeTokenEstimator;
use PHPUnit\Framework\TestCase;

class ConservativeTokenEstimatorTest extends TestCase
{
    public function test_estimates_utf8_bytes_instead_of_characters_or_exact_model_tokens(): void
    {
        $estimator = new ConservativeTokenEstimator;
        $this->assertSame(0, $estimator->estimate(''));
        $this->assertSame(4, $estimator->estimate('abcd'));
        $this->assertSame(2, $estimator->estimate('ї'));
        $this->assertSame(4, $estimator->estimate('🙂'));
        $this->assertSame(6, $estimator->estimate('ї🙂'));
        $this->assertSame('utf8-bytes-v1', ConservativeTokenEstimator::METHOD);
    }
}
