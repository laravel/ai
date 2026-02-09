<?php

namespace Tests\Unit\Gateway\Prism;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\Prism\PrismException;
use Laravel\Ai\Providers\Provider;
use Mockery;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Exceptions\PrismException as PrismVendorException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;

class PrismExceptionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function mockProvider(string $name = 'openai'): Provider
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('name')->andReturn($name);

        return $provider;
    }

    public function test_tool_failed_with_null_previous_returns_ai_exception_instead_of_crashing(): void
    {
        // Previously, this would throw Error("Can only throw objects") because
        // getPrevious() returns null and the code tried to `throw null`.
        // After the fix, it falls through and returns an AiException.
        $exception = new PrismVendorException('Calling search tool failed');

        $this->assertNull($exception->getPrevious());

        $result = PrismException::toAiException($exception, $this->mockProvider(), 'gpt-4');

        $this->assertInstanceOf(AiException::class, $result);
        $this->assertEquals('Calling search tool failed', $result->getMessage());
    }

    public function test_tool_failed_with_previous_exception_rethrows_previous(): void
    {
        $previous = new \RuntimeException('Tool execution error');
        $exception = new PrismVendorException('Calling search tool failed', previous: $previous);

        try {
            PrismException::toAiException($exception, $this->mockProvider(), 'gpt-4');
            $this->fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($previous, $e);
            $this->assertEquals('Tool execution error', $e->getMessage());
        }
    }

    public function test_generic_prism_exception_returns_ai_exception(): void
    {
        $exception = new PrismVendorException('Something went wrong', 500);

        $result = PrismException::toAiException($exception, $this->mockProvider(), 'gpt-4');

        $this->assertInstanceOf(AiException::class, $result);
        $this->assertEquals('Something went wrong', $result->getMessage());
        $this->assertEquals(500, $result->getCode());
    }

    public function test_rate_limited_exception_throws_rate_limited(): void
    {
        $exception = new PrismRateLimitedException(rateLimits: []);

        $this->expectException(RateLimitedException::class);
        $this->expectExceptionMessage('Application rate limited by AI provider [openai].');

        PrismException::toAiException($exception, $this->mockProvider(), 'gpt-4');
    }

    public function test_provider_overloaded_exception_throws_provider_overloaded(): void
    {
        $exception = new PrismProviderOverloadedException('openai');

        $this->expectException(ProviderOverloadedException::class);
        $this->expectExceptionMessage('AI provider [anthropic] is overloaded.');

        PrismException::toAiException($exception, $this->mockProvider('anthropic'), 'claude-3');
    }
}
