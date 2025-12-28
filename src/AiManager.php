<?php

namespace Laravel\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\MultipleInstanceManager;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\ElevenLabsProvider;
use Laravel\Ai\Providers\FakeProvider;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\GroqProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\XaiProvider;
use LogicException;

class AiManager extends MultipleInstanceManager
{
    /**
     * All of the registered fake agent providers.
     */
    protected array $fakeAgentProviders = [];

    /**
     * The key name of the "driver" equivalent configuration option.
     *
     * @var string
     */
    protected $driverKey = 'driver';

    /**
     * Get a provider instance by name.
     *
     * @param  string|null  $name
     */
    public function audioProvider($name = null): AudioProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof AudioProvider) {
                throw new LogicException('Provider ['.get_class($instance).'] does not support audio generation.');
            }
        });
    }

    /**
     * Get a provider instance by name.
     *
     * @param  string|null  $name
     */
    public function embeddingProvider($name = null): EmbeddingProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof EmbeddingProvider) {
                throw new LogicException('Provider ['.get_class($instance).'] does not support embedding generation.');
            }
        });
    }

    /**
     * Get a provider instance by name.
     *
     * @param  string|null  $name
     */
    public function imageProvider($name = null): ImageProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof ImageProvider) {
                throw new LogicException('Provider ['.get_class($instance).'] does not support image generation.');
            }
        });
    }

    /**
     * Get a provider instance by name.
     *
     * @param  string|null  $name
     */
    public function textProvider($name = null): TextProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof TextProvider) {
                throw new LogicException('Provider ['.get_class($instance).'] does not support text generation.');
            }
        });
    }

    /**
     * Get a provider instance by name.
     *
     * @param  string|null  $name
     */
    public function transcriptionProvider($name = null): TranscriptionProvider
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
    public function fakeAgent(string $agent, array $responses = []): FakeProvider
    {
        $this->fakeAgentProviders[$agent] = new FakeProvider(
            $responses,
            $this->app['events']
        );

        return $this->fakeAgentProviders[$agent];
    }

    /**
     * Determine if the given agent has been faked.
     */
    public function hasFakeAgent(Agent|string $agent): bool
    {
        return array_key_exists(
            is_object($agent) ? get_class($agent) : $agent,
            $this->fakeAgentProviders
        );
    }

    /**
     * Get a fake provider instance for the given agent.
     */
    public function fakeProviderFor(
        Agent $agent,
        Provider $originalProvider,
        string $originalModel): FakeProvider
    {
        if (! $this->hasFakeAgent($agent)) {
            throw new InvalidArgumentException('Agent ['.get_class($agent).'] has not been faked.');
        }

        return $this->fakeAgentProviders[get_class($agent)]
            ->withOriginalProvider($originalProvider, $originalModel);
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
