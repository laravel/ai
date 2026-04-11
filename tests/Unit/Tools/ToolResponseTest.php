<?php

namespace Tests\Unit\Tools;

use Laravel\Ai\Tools\ToolResponse;
use PHPUnit\Framework\TestCase;

class ToolResponseTest extends TestCase
{
    public function test_make_creates_instance_with_result(): void
    {
        $response = ToolResponse::make('hello');

        $this->assertSame('hello', $response->result);
        $this->assertNull($response->meta);
    }

    public function test_with_meta_returns_new_instance_with_metadata(): void
    {
        $response = ToolResponse::make('result')
            ->withMeta(['thinking' => 'Working on it…']);

        $this->assertSame('result', $response->result);
        $this->assertSame(['thinking' => 'Working on it…'], $response->meta);
    }

    public function test_with_meta_does_not_mutate_original(): void
    {
        $original = ToolResponse::make('result');
        $withMeta = $original->withMeta(['thinking' => 'Working on it…']);

        $this->assertNull($original->meta);
        $this->assertSame(['thinking' => 'Working on it…'], $withMeta->meta);
    }

    public function test_string_cast_returns_model_payload_only(): void
    {
        $response = ToolResponse::make('payload')
            ->withMeta(['label' => 'test']);

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

        $this->assertSame('from stringable', $response->result);
    }

    public function test_meta_is_null_by_default(): void
    {
        $response = ToolResponse::make('data');

        $this->assertNull($response->meta);
    }
}
