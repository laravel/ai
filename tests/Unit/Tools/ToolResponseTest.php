<?php

namespace Tests\Unit\Tools;

use Laravel\Ai\Tools\ToolResponse;
use PHPUnit\Framework\TestCase;

class ToolResponseTest extends TestCase
{
    public function test_make_creates_instance_with_result(): void
    {
        $response = ToolResponse::make('hello');

        $this->assertSame('hello', $response->result());
        $this->assertNull($response->getMeta());
    }

    public function test_meta_sets_and_returns_metadata(): void
    {
        $response = ToolResponse::make('result')
            ->meta(['thinking' => 'Working on it…']);

        $this->assertSame('result', $response->result());
        $this->assertSame(['thinking' => 'Working on it…'], $response->getMeta());
    }

    public function test_string_cast_returns_model_payload_only(): void
    {
        $response = ToolResponse::make('payload')
            ->meta(['label' => 'test']);

        $this->assertSame('payload', (string) $response);
    }

    public function test_make_accepts_stringable(): void
    {
        $stringable = new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'from stringable';
            }
        };

        $response = ToolResponse::make($stringable);

        $this->assertSame('from stringable', $response->result());
    }

    public function test_meta_is_null_by_default(): void
    {
        $response = ToolResponse::make('data');

        $this->assertNull($response->getMeta());
    }
}
