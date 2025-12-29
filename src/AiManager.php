<?php

namespace Laravel\Ai;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\MultipleInstanceManager;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\FakeGateway;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\ElevenLabsProvider;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\GroqProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\XaiProvider;
use LogicException;

class AiManager extends MultipleInstanceManager
{
    /**
     * All of the registered fake agent gateways.
     */
    protected array $fakeAgentGateways = [];

    /**
     * The key name of the "driver" equivalent configuration option.
     *
     * @var string
     */
    protected $driverKey = 'driver';

    /**
     * Get a provider instance by name.
     */
    public function audioProvider(?string $name = null): AudioProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof AudioProvider) {
                throw new LogicException('Provider ['.get_class($instance).'] does not support audio generation.');
            }
        });
    }

    /**
     * Get a provider instance by name.
     */
    public function embeddingProvider(?string $name = null): EmbeddingProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof EmbeddingProvider) {
                throw new LogicException('Provider ['.get_class($instance).'] does not support embedding generation.');
            }
        });
    }

    /**
     * Get a provider instance by name.
     */
    public function imageProvider(?string $name = null): ImageProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof ImageProvider) {
                throw new LogicException('Provider ['.get_class($instance).'] does not support image generation.');
            }
        });
    }

    /**
     * Get a provider instance by name.
     */
    public function textProvider(?string $name = null): TextProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof TextProvider) {
                throw new LogicException('Provider ['.get_class($instance).'] does not support text generation.');
            }
        });
    }

    /**
     * Get a provider instance by name.
     */
    public function textProviderFor(Agent $agent, ?string $name = null): TextProvider
    {
        $provider = $this->textProvider($name);

        if ($this->hasFakeAgent($agent)) {
            $provider = clone $provider;

            $provider->useTextGateway($this->fakeGatewayFor($agent));
        }

        return $provider;
    }

    /**
     * Get a provider instance by name.
     */
    public function transcriptionProvider(?string $name = null): TranscriptionProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof TranscriptionProvider) {
                throw new LogicException('Provider ['.get_class($instance).'] does not support transcription generation.');
            }
        });
    }

    /**
     * Create an Anthropic powered instance.
     */
    public function createAnthropicDriver(array $config): AnthropicProvider
    {
        return new AnthropicProvider(
            new PrismGateway($this->app['events']),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an Eleven Labs powered instance.
     */
    public function createElevenDriver(array $config): ElevenLabsProvider
    {
        return new ElevenLabsProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an Gemini powered instance.
     */
    public function createGeminiDriver(array $config): GeminiProvider
    {
        return new GeminiProvider(
            new PrismGateway($this->app['events']),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an Groq powered instance.
     */
    public function createGroqDriver(array $config): GroqProvider
    {
        return new GroqProvider(
            new PrismGateway($this->app['events']),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an OpenAI powered instance.
     */
    public function createOpenaiDriver(array $config): OpenAiProvider
    {
        return new OpenAiProvider(
            new PrismGateway($this->app['events']),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an xAI powered instance.
     */
    public function createXaiDriver(array $config): XaiProvider
    {
        return new XaiProvider(
            new PrismGateway($this->app['events']),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Fake the responses returned by the given agent.
     */
    public function fakeAgent(string $agent, Closure|array $responses = []): FakeGateway
    {
        return tap(
            new FakeGateway($responses),
            fn ($gateway) => $this->fakeAgentGateways[$agent] = $gateway
        );
    }

    /**
     * Determine if the given agent has been faked.
     */
    public function hasFakeAgent(Agent|string $agent): bool
    {
        return array_key_exists(
            is_object($agent) ? get_class($agent) : $agent,
            $this->fakeAgentGateways
        );
    }

    /**
     * Get a fake gateway instance for the given agent.
     */
    public function fakeGatewayFor(Agent $agent): FakeGateway
    {
        return $this->hasFakeAgent($agent)
            ? $this->fakeAgentGateways[get_class($agent)]
            : throw new InvalidArgumentException('Agent ['.get_class($agent).'] has not been faked.');
    }

    /**
     * Get the default instance name.
     *
     * @return string
     */
    public function getDefaultInstance()
    {
        return $this->app['config']['ai.default'];
    }

    /**
     * Set the default instance name.
     *
     * @param  string  $name
     * @return void
     */
    public function setDefaultInstance($name)
    {
        $this->app['config']['ai.default'] = $name;
    }

    /**
     * Get the instance specific configuration.
     *
     * @param  string  $name
     * @return array
     */
    public function getInstanceConfig($name)
    {
        return $this->app['config']->get(
            'ai.providers.'.$name, ['driver' => $name],
        );
    }
}
