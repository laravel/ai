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
        $this->assertEquals(100, $usage->inputTokens['total']);
        $this->assertEquals(50, $usage->outputTokens['completion']);
        $this->assertEquals(10, $usage->cachedTokens['write']);
        $this->assertEquals(5, $usage->cachedTokens['read']);
        $this->assertEquals(20, $usage->outputTokens['thought']);
    }

    public function test_handles_null_usage(): void
    {
        $usage = PrismUsage::toLaravelUsage(null);

        $this->assertInstanceOf(Usage::class, $usage);
        $this->assertEquals(0, $usage->inputTokens['total']);
        $this->assertEquals(0, $usage->outputTokens['completion']);
        $this->assertEquals(0, $usage->cachedTokens['write']);
        $this->assertEquals(0, $usage->cachedTokens['read']);
        $this->assertEquals(0, $usage->outputTokens['thought']);
    }
}
