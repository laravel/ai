<?php

namespace Tests\Unit\Gateway\Prism;

use Laravel\Ai\Gateway\Prism\PrismUsage;
use Laravel\Ai\Responses\Data\Usage;
use PHPUnit\Framework\TestCase;
use Prism\Prism\ValueObjects\Usage as PrismUsageValueObject;

class PrismUsageTest extends TestCase
{
    public function test_converts_prism_usage_to_laravel_usage(): void
    {
        $prismUsage = new PrismUsageValueObject(
            promptTokens: 100,
            completionTokens: 50,
            cacheWriteInputTokens: 10,
            cacheReadInputTokens: 5,
            thoughtTokens: 20,
        );

        $usage = PrismUsage::toLaravelUsage($prismUsage);

        $this->assertInstanceOf(Usage::class, $usage);
        $this->assertEquals(100, $usage->promptTokens);
        $this->assertEquals(50, $usage->completionTokens);
        $this->assertEquals(10, $usage->cacheWriteInputTokens);
        $this->assertEquals(5, $usage->cacheReadInputTokens);
        $this->assertEquals(20, $usage->reasoningTokens);
    }

    public function test_handles_null_usage(): void
    {
        $usage = PrismUsage::toLaravelUsage(null);

        $this->assertInstanceOf(Usage::class, $usage);
        $this->assertEquals(0, $usage->promptTokens);
        $this->assertEquals(0, $usage->completionTokens);
        $this->assertEquals(0, $usage->cacheWriteInputTokens);
        $this->assertEquals(0, $usage->cacheReadInputTokens);
        $this->assertEquals(0, $usage->reasoningTokens);
    }
}
