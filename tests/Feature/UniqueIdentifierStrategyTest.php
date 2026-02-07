<?php

namespace Tests\Feature;

use Laravel\Ai\Contracts\UniqueIdentifierGenerator;
use Laravel\Ai\Support\Generators\RandomHexGenerator;
use Laravel\Ai\Support\Generators\UlidGenerator;
use Laravel\Ai\Support\Generators\UuidV4Generator;
use Laravel\Ai\Support\Generators\UuidV7Generator;
use Tests\Feature\Agents\AssistantAgent;
use Tests\TestCase;

class UniqueIdentifierStrategyTest extends TestCase
{
    public function test_default_generator_is_uuid_v7(): void
    {
        $generator = resolve(UniqueIdentifierGenerator::class);
        $this->assertInstanceOf(UuidV7Generator::class, $generator);
    }

    public function test_generator_can_be_configured_to_uuid_v4(): void
    {
        config(['ai.database.id_generator' => UuidV4Generator::class]);
        $this->app->singleton(UniqueIdentifierGenerator::class, fn ($app) => new ($app['config']['ai.database.id_generator'])());

        $generator = resolve(UniqueIdentifierGenerator::class);
        $this->assertInstanceOf(UuidV4Generator::class, $generator);

        $id = $generator->generate();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
    }

    public function test_generator_can_be_configured_to_ulid(): void
    {
        config(['ai.database.id_generator' => UlidGenerator::class]);
        $this->app->singleton(UniqueIdentifierGenerator::class, fn ($app) => new ($app['config']['ai.database.id_generator'])());

        $generator = resolve(UniqueIdentifierGenerator::class);
        $this->assertInstanceOf(UlidGenerator::class, $generator);

        $id = $generator->generate();
        $this->assertEquals(26, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $id);
    }

    public function test_generator_can_be_configured_to_random_hex(): void
    {
        config(['ai.database.id_generator' => RandomHexGenerator::class]);
        $this->app->singleton(UniqueIdentifierGenerator::class, fn ($app) => new ($app['config']['ai.database.id_generator'])());

        $generator = resolve(UniqueIdentifierGenerator::class);
        $this->assertInstanceOf(RandomHexGenerator::class, $generator);

        $id = $generator->generate();
        $this->assertEquals(32, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function test_agent_invocation_uses_configured_generator(): void
    {
        config(['ai.database.id_generator' => UlidGenerator::class]);
        $this->app->singleton(UniqueIdentifierGenerator::class, fn ($app) => new ($app['config']['ai.database.id_generator'])());

        AssistantAgent::fake(['response']);

        $response = (new AssistantAgent)->prompt('test');

        // ULID is 26 chars lowercase
        $this->assertEquals(26, strlen($response->invocationId));
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $response->invocationId);
    }

    public function test_agent_invocation_uses_random_hex_generator(): void
    {
        config(['ai.database.id_generator' => RandomHexGenerator::class]);
        $this->app->singleton(UniqueIdentifierGenerator::class, fn ($app) => new ($app['config']['ai.database.id_generator'])());

        AssistantAgent::fake(['response']);

        $response = (new AssistantAgent)->prompt('test');

        // RandomHex is 32 hex chars
        $this->assertEquals(32, strlen($response->invocationId));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $response->invocationId);
    }

    public function test_default_uuid_v7_produces_uuid_format_invocation_ids(): void
    {
        AssistantAgent::fake(['response']);

        $response = (new AssistantAgent)->prompt('test');

        $this->assertEquals(36, strlen($response->invocationId));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $response->invocationId);
    }

    public function test_generator_is_resolved_as_singleton(): void
    {
        $generator1 = resolve(UniqueIdentifierGenerator::class);
        $generator2 = resolve(UniqueIdentifierGenerator::class);

        $this->assertSame($generator1, $generator2);
    }

    public function test_generator_length_matches_generated_output(): void
    {
        $generators = [
            UuidV7Generator::class,
            UuidV4Generator::class,
            UlidGenerator::class,
            RandomHexGenerator::class,
        ];

        foreach ($generators as $generatorClass) {
            $generator = new $generatorClass;
            $id = $generator->generate();

            $this->assertEquals($generator->length(), strlen($id), "{$generatorClass}::length() does not match actual output length.");
        }
    }
}
