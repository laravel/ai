<?php

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\QueuedAgentPrompt;
use Tests\Fixtures\Agents\AssistantAgent;

describe('Ai::build()', function () {
    test('builds an on-demand provider from a config array', function () {
        $provider = Ai::build([
            'driver' => 'openai',
            'key' => 'on-demand-key',
        ]);

        expect($provider)->toBeInstanceOf(OpenAiProvider::class);
        expect($provider->name())->toBe('ondemand');
        expect($provider->driver())->toBe('openai');
        expect($provider->providerCredentials()['key'])->toBe('on-demand-key');
    });

    test('honors a name supplied in the config array', function () {
        $provider = Ai::build([
            'driver' => 'openai',
            'name' => 'tenant-foo',
            'key' => 'k',
        ]);

        expect($provider->name())->toBe('tenant-foo');
    });

    test('accepts a Lab enum as the driver', function () {
        $provider = Ai::build([
            'driver' => Lab::Anthropic,
            'key' => 'k',
        ]);

        expect($provider)->toBeInstanceOf(AnthropicProvider::class);
        expect($provider->driver())->toBe('anthropic');
    });

    test('passes additional config keys through to the provider', function () {
        $provider = Ai::build([
            'driver' => 'openai',
            'key' => 'k',
            'organization' => 'org_123',
            'project' => 'proj_456',
        ]);

        expect($provider->additionalConfiguration())->toMatchArray([
            'organization' => 'org_123',
            'project' => 'proj_456',
        ]);
    });

    test('throws when the driver key is missing', function () {
        Ai::build(['key' => 'k']);
    })->throws(InvalidArgumentException::class, 'must specify a [driver]');

    test('throws when the driver is not supported', function () {
        Ai::build(['driver' => 'nonexistent', 'key' => 'k']);
    })->throws(InvalidArgumentException::class, '[nonexistent] is not supported');

    test('resolves through Ai::extend()-registered custom drivers', function () {
        Ai::extend('custom-driver', fn ($app, array $config) => new OpenAiProvider(
            $app->make(OpenAiGateway::class),
            $config,
            $app->make(Dispatcher::class),
        ));

        $provider = Ai::build(['driver' => 'custom-driver', 'name' => 'tenant-bar', 'key' => 'k']);

        expect($provider)->toBeInstanceOf(OpenAiProvider::class);
        expect($provider->name())->toBe('tenant-bar');
    });

    test('does not cache the instance under a name', function () {
        $first = Ai::build(['driver' => 'openai', 'name' => 'shared', 'key' => 'first']);
        $second = Ai::build(['driver' => 'openai', 'name' => 'shared', 'key' => 'second']);

        expect($first)->not->toBe($second);
        expect($first->providerCredentials()['key'])->toBe('first');
        expect($second->providerCredentials()['key'])->toBe('second');
    });

    test('does not mutate the configured providers', function () {
        $original = config('ai.providers.openai.key');

        Ai::build(['driver' => 'openai', 'name' => 'openai', 'key' => 'on-demand-key']);

        expect(config('ai.providers.openai.key'))->toBe($original);
    });
});

describe('agents accept an on-demand provider via the provider argument', function () {
    test('a single on-demand provider becomes the resolved provider', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Hello',
            provider: Ai::build(['driver' => 'anthropic', 'key' => 'tenant-anth-key']),
        );

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->provider->driver() === 'anthropic'
                && $prompt->provider->providerCredentials()['key'] === 'tenant-anth-key';
        });
    });

    test('explicit model argument is forwarded alongside an on-demand provider', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Hello',
            provider: Ai::build(['driver' => 'openai', 'key' => 'k']),
            model: 'gpt-4o',
        );

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->model === 'gpt-4o';
        });
    });

    test('an on-demand provider participates in a failover list alongside provider names', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Hello',
            provider: [Ai::build(['driver' => 'anthropic', 'key' => 'tenant-k']), 'openai'],
        );

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->provider->driver() === 'anthropic'
                && $prompt->provider->providerCredentials()['key'] === 'tenant-k';
        });
    });

    test('stream accepts an on-demand provider', function () {
        AssistantAgent::fake(['streamed']);

        $response = (new AssistantAgent)->stream('Hello',
            provider: Ai::build(['driver' => 'openai', 'key' => 'k']),
        );

        expect($response)->not->toBeNull();
    });

    test('throws when an on-demand provider does not support text generation', function () {
        (new AssistantAgent)->prompt('Hello',
            provider: Ai::build(['driver' => 'eleven', 'key' => 'k']),
        );
    })->throws(LogicException::class, 'does not support text generation');

    test('queue accepts an on-demand provider', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->queue('Hello',
            provider: Ai::build(['driver' => 'anthropic', 'key' => 'tenant-k']),
        );

        AssistantAgent::assertQueued(function (QueuedAgentPrompt $prompt) {
            return $prompt->provider instanceof AnthropicProvider
                && $prompt->provider->providerCredentials()['key'] === 'tenant-k';
        });
    });

    test('broadcast on queue accepts an on-demand provider', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->broadcastOnQueue('Hello', [],
            provider: Ai::build(['driver' => 'openai', 'key' => 'tenant-k']),
        );

        AssistantAgent::assertQueued(function (QueuedAgentPrompt $prompt) {
            return $prompt->provider instanceof OpenAiProvider
                && $prompt->provider->providerCredentials()['key'] === 'tenant-k';
        });
    });
});
