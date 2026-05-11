<?php

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\ProviderConfig;
use Laravel\Ai\Providers\OpenAiProvider;
use Tests\Fixtures\Agents\AssistantAgent;

describe('Ai::provider() factory', function () {
    test('builds a ProviderConfig from a config array', function () {
        $config = Ai::provider('anthropic', ['key' => 'tenant-key', 'url' => 'https://proxy.test/v1']);

        expect($config)->toBeInstanceOf(ProviderConfig::class);
        expect($config->name())->toBe('anthropic');
        expect($config->overrides())->toBe([
            'key' => 'tenant-key',
            'url' => 'https://proxy.test/v1',
        ]);
    });

    test('partial overrides are allowed', function () {
        $config = Ai::provider('openai', ['key' => 'k']);

        expect($config->overrides())->toBe(['key' => 'k']);
    });

    test('an empty config array is allowed', function () {
        $config = Ai::provider('openai');

        expect($config->overrides())->toBe([]);
    });

    test('accepts a Lab enum for the name', function () {
        $config = Ai::provider(Lab::Anthropic, ['key' => 'k']);

        expect($config->name())->toBe('anthropic');
    });

    test('a name key in the config array is ignored', function () {
        $config = Ai::provider('openai', ['name' => 'something-else', 'key' => 'k']);

        expect($config->name())->toBe('openai');
        expect($config->overrides())->toBe(['key' => 'k']);
    });

    test('provider-specific keys pass through unchanged', function () {
        $config = Ai::provider('openai', [
            'key' => 'k',
            'organization' => 'org_123',
            'project' => 'proj_456',
            'headers' => ['X-Tenant' => 'foo'],
        ]);

        expect($config->overrides())->toBe([
            'key' => 'k',
            'organization' => 'org_123',
            'project' => 'proj_456',
            'headers' => ['X-Tenant' => 'foo'],
        ]);
    });

    test('refuses to serialize so credentials cannot leak into queues', function () {
        $config = Ai::provider('anthropic', ['key' => 'secret']);

        serialize($config);
    })->throws(LogicException::class, 'must not be serialized');
});

describe('runtime config flowing through AiManager', function () {
    test('overrides the key for an existing provider while keeping base url', function () {
        $originalUrl = config('ai.providers.openai.url');

        $provider = Ai::textProviderFor(new AssistantAgent, Ai::provider('openai', ['key' => 'runtime-key']));

        expect($provider)->toBeInstanceOf(OpenAiProvider::class);
        expect($provider->providerCredentials()['key'])->toBe('runtime-key');
        expect($provider->additionalConfiguration()['url'] ?? null)->toBe($originalUrl);
    });

    test('creates an entirely new provider via driver override', function () {
        $provider = Ai::textProviderFor(new AssistantAgent,
            Ai::provider('tenant-foo', ['driver' => 'openai', 'key' => 'tenant-foo-key']),
        );

        expect($provider)->toBeInstanceOf(OpenAiProvider::class);
        expect($provider->name())->toBe('tenant-foo');
        expect($provider->providerCredentials()['key'])->toBe('tenant-foo-key');
    });

    test('throws if no driver can be resolved', function () {
        Ai::textProviderFor(new AssistantAgent, Ai::provider('nonexistent-provider', ['key' => 'k']));
    })->throws(InvalidArgumentException::class, 'no driver');

    test('routes through Ai::extend()-registered custom drivers', function () {
        Ai::extend('custom-driver', fn ($app, array $config) => new OpenAiProvider(
            $app->make(OpenAiGateway::class),
            $config,
            $app->make(Dispatcher::class),
        ));

        $provider = Ai::textProviderFor(new AssistantAgent,
            Ai::provider('tenant-bar', ['driver' => 'custom-driver', 'key' => 'k']),
        );

        expect($provider)->toBeInstanceOf(OpenAiProvider::class);
        expect($provider->name())->toBe('tenant-bar');
    });

    test('does not mutate global config', function () {
        $original = config('ai.providers.openai.key');

        Ai::textProviderFor(new AssistantAgent, Ai::provider('openai', ['key' => 'runtime-key']));

        expect(config('ai.providers.openai.key'))->toBe($original);
    });
});

describe('agents accept a runtime provider config via the provider argument', function () {
    test('a single ProviderConfig becomes the resolved provider', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Hello',
            provider: Ai::provider('anthropic', ['key' => 'tenant-anth-key']),
        );

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->provider->name() === 'anthropic'
                && $prompt->provider->providerCredentials()['key'] === 'tenant-anth-key';
        });
    });

    test('explicit model argument is forwarded alongside a ProviderConfig', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Hello',
            provider: Ai::provider('openai', ['key' => 'k']),
            model: 'gpt-4o',
        );

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->model === 'gpt-4o';
        });
    });

    test('ProviderConfig participates in a failover list alongside provider names', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Hello',
            provider: [Ai::provider('anthropic', ['key' => 'tenant-k']), 'openai'],
        );

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->provider->name() === 'anthropic'
                && $prompt->provider->providerCredentials()['key'] === 'tenant-k';
        });
    });

    test('stream accepts a ProviderConfig', function () {
        AssistantAgent::fake(['streamed']);

        $response = (new AssistantAgent)->stream('Hello',
            provider: Ai::provider('openai', ['key' => 'k']),
        );

        expect($response)->not->toBeNull();
    });
});

describe('queue and broadcastOnQueue refuse a runtime ProviderConfig', function () {
    test('queue() throws when given a ProviderConfig', function () {
        (new AssistantAgent)->queue('Hello',
            provider: Ai::provider('anthropic', ['key' => 'tenant-key']),
        );
    })->throws(InvalidArgumentException::class, 'must not be serialized');

    test('queue() throws when a ProviderConfig appears in a failover array', function () {
        (new AssistantAgent)->queue('Hello',
            provider: ['openai', Ai::provider('anthropic', ['key' => 'tenant-key'])],
        );
    })->throws(InvalidArgumentException::class, 'must not be serialized');

    test('broadcastOnQueue() throws when given a ProviderConfig', function () {
        (new AssistantAgent)->broadcastOnQueue('Hello', [],
            provider: Ai::provider('anthropic', ['key' => 'tenant-key']),
        );
    })->throws(InvalidArgumentException::class, 'must not be serialized');

    test('queue() still works without a ProviderConfig', function () {
        AssistantAgent::fake();

        $response = (new AssistantAgent)->queue('Hello');

        expect($response)->not->toBeNull();
    });
});
