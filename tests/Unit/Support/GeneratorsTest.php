<?php

namespace Tests\Unit\Support;

use Laravel\Ai\Support\Generators\RandomHexGenerator;
use Laravel\Ai\Support\Generators\UlidGenerator;
use Laravel\Ai\Support\Generators\UuidV4Generator;
use Laravel\Ai\Support\Generators\UuidV7Generator;
use PHPUnit\Framework\TestCase;

class GeneratorsTest extends TestCase
{
    public function test_uuid_v7_returns_string(): void
    {
        $generator = new UuidV7Generator;
        $result = $generator->generate();

        $this->assertIsString($result);
    }

    public function test_uuid_v7_has_correct_length(): void
    {
        $generator = new UuidV7Generator;
        $result = $generator->generate();

        $this->assertEquals(36, strlen($result));
    }

    public function test_uuid_v7_has_correct_format(): void
    {
        $generator = new UuidV7Generator;
        $result = $generator->generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $result);
    }

    public function test_uuid_v7_generates_unique_values(): void
    {
        $generator = new UuidV7Generator;
        $result1 = $generator->generate();
        $result2 = $generator->generate();

        $this->assertNotEquals($result1, $result2);
    }

    public function test_uuid_v4_returns_string(): void
    {
        $generator = new UuidV4Generator;
        $result = $generator->generate();

        $this->assertIsString($result);
    }

    public function test_uuid_v4_has_correct_length(): void
    {
        $generator = new UuidV4Generator;
        $result = $generator->generate();

        $this->assertEquals(36, strlen($result));
    }

    public function test_uuid_v4_has_correct_format(): void
    {
        $generator = new UuidV4Generator;
        $result = $generator->generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $result);
    }

    public function test_uuid_v4_generates_unique_values(): void
    {
        $generator = new UuidV4Generator;
        $result1 = $generator->generate();
        $result2 = $generator->generate();

        $this->assertNotEquals($result1, $result2);
    }

    public function test_ulid_returns_string(): void
    {
        $generator = new UlidGenerator;
        $result = $generator->generate();

        $this->assertIsString($result);
    }

    public function test_ulid_has_correct_length(): void
    {
        $generator = new UlidGenerator;
        $result = $generator->generate();

        $this->assertEquals(26, strlen($result));
    }

    public function test_ulid_has_correct_format(): void
    {
        $generator = new UlidGenerator;
        $result = $generator->generate();

        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $result);
    }

    public function test_ulid_generates_unique_values(): void
    {
        $generator = new UlidGenerator;
        $result1 = $generator->generate();
        $result2 = $generator->generate();

        $this->assertNotEquals($result1, $result2);
    }

    public function test_random_hex_returns_string(): void
    {
        $generator = new RandomHexGenerator;
        $result = $generator->generate();

        $this->assertIsString($result);
    }

    public function test_random_hex_has_correct_length(): void
    {
        $generator = new RandomHexGenerator;
        $result = $generator->generate();

        $this->assertEquals(32, strlen($result));
    }

    public function test_random_hex_has_correct_format(): void
    {
        $generator = new RandomHexGenerator;
        $result = $generator->generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result);
    }

    public function test_random_hex_generates_unique_values(): void
    {
        $generator = new RandomHexGenerator;
        $result1 = $generator->generate();
        $result2 = $generator->generate();

        $this->assertNotEquals($result1, $result2);
    }
}
