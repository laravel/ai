<?php

namespace Tests\Unit\Gateway\OpenAi;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\Feature\Tools\RandomNumberGenerator;

class MapsToolsTest extends TestCase
{
    protected function callMapTool($tool): array
    {
        $dispatcher = new class implements Dispatcher
        {
            public function listen($events, $listener = null) {}

            public function hasListeners($eventName) {}

            public function subscribe($subscriber) {}

            public function until($event, $payload = []) {}

            public function dispatch($event, $payload = [], $halt = false) {}

            public function push($event, $payload = []) {}

            public function flush($event) {}

            public function forget($event) {}

            public function forgetPushed() {}
        };

        $gateway = new OpenAiGateway($dispatcher);

        $method = new ReflectionMethod($gateway, 'mapTool');

        return $method->invoke($gateway, $tool);
    }

    public function test_tool_with_empty_schema_includes_strict_compliant_parameters(): void
    {
        $result = $this->callMapTool(new FixedNumberGenerator);

        $this->assertSame('function', $result['type']);
        $this->assertSame('FixedNumberGenerator', $result['name']);
        $this->assertTrue($result['strict']);
        $this->assertArrayHasKey('parameters', $result);
        $this->assertSame('object', $result['parameters']['type']);
        $this->assertEquals((object) [], $result['parameters']['properties']);
        $this->assertSame([], $result['parameters']['required']);
        $this->assertFalse($result['parameters']['additionalProperties']);
    }

    public function test_tool_with_schema_includes_parameters(): void
    {
        $result = $this->callMapTool(new RandomNumberGenerator);

        $this->assertSame('function', $result['type']);
        $this->assertSame('RandomNumberGenerator', $result['name']);
        $this->assertTrue($result['strict']);
        $this->assertArrayHasKey('parameters', $result);
        $this->assertSame('object', $result['parameters']['type']);
        $this->assertArrayHasKey('min', $result['parameters']['properties']);
        $this->assertArrayHasKey('max', $result['parameters']['properties']);
        $this->assertContains('min', $result['parameters']['required']);
        $this->assertContains('max', $result['parameters']['required']);
        $this->assertFalse($result['parameters']['additionalProperties']);
    }
}
