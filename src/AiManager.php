<?php

namespace Laravel\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\MultipleInstanceManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Providers\StoreProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\CohereProvider;
use Laravel\Ai\Providers\DeepSeekProvider;
use Laravel\Ai\Providers\ElevenLabsProvider;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\GroqProvider;
use Laravel\Ai\Providers\JinaProvider;
use Laravel\Ai\Providers\MistralProvider;
use Laravel\Ai\Providers\OllamaProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\OpenRouterProvider;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\VoyageAiProvider;
use Laravel\Ai\Providers\XaiProvider;
use LogicException;

class AiManager extends MultipleInstanceManager
{
    use Concerns\InteractsWithFakeAgents;
    use Concerns\InteractsWithFakeAudio;
    use Concerns\InteractsWithFakeEmbeddings;
    use Concerns\InteractsWithFakeFiles;
    use Concerns\InteractsWithFakeImages;
    use Concerns\InteractsWithFakeReranking;
    use Concerns\InteractsWithFakeStores;
    use Concerns\InteractsWithFakeTranscriptions;

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
                throw new LogicException('Provider ['. $instance::class .'] does not support audio generation.');
            }
        });
    }

    /**
     * Get an audio provider instance, using a fake gateway if audio is faked.
     */
    public function fakeableAudioProvider(?string $name = null): AudioProvider
    {
        $provider = $this->audioProvider($name);

        return $this->audioIsFaked()
            ? (clone $provider)->useAudioGateway($this->fakeAudioGateway())
            : $provider;
    }

    /**
     * Get a provider instance by name.
     */
    public function embeddingProvider(?string $name = null): EmbeddingProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof EmbeddingProvider) {
                throw new LogicException('Provider ['. $instance::class .'] does not support embedding generation.');
            }
        });
    }

    /**
     * Get an embedding provider instance, using a fake gateway if embeddings are faked.
     */
    public function fakeableEmbeddingProvider(?string $name = null): EmbeddingProvider
    {
        $provider = $this->embeddingProvider($name);

        return $this->embeddingsAreFaked()
            ? (clone $provider)->useEmbeddingGateway($this->fakeEmbeddingGateway())
            : $provider;
    }

    /**
     * Get a reranking provider instance by name.
     */
    public function rerankingProvider(?string $name = null): RerankingProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof RerankingProvider) {
                throw new LogicException('Provider ['. $instance::class .'] does not support reranking.');
            }
        });
    }

    /**
     * Get a reranking provider instance, using a fake gateway if reranking is faked.
     */
    public function fakeableRerankingProvider(?string $name = null): RerankingProvider
    {
        $provider = $this->rerankingProvider($name);

        return $this->rerankingIsFaked()
            ? (clone $provider)->useRerankingGateway($this->fakeRerankingGateway())
            : $provider;
    }

    /**
     * Get a provider instance by name.
     */
    public function imageProvider(?string $name = null): ImageProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof ImageProvider) {
                throw new LogicException('Provider ['. $instance::class .'] does not support image generation.');
            }
        });
    }

    /**
     * Get an image provider instance, using a fake gateway if images are faked.
     */
    public function fakeableImageProvider(?string $name = null): ImageProvider
    {
        $provider = $this->imageProvider($name);

        return $this->imagesAreFaked()
            ? (clone $provider)->useImageGateway($this->fakeImageGateway())
            : $provider;
    }

    /**
     * Get a provider instance by name.
     */
    public function textProvider(?string $name = null): TextProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof TextProvider) {
                throw new LogicException('Provider ['. $instance::class .'] does not support text generation.');
            }
        });
    }

    /**
     * Get a provider instance for an agent by name.
     */
    public function textProviderFor(Agent $agent, ?string $name = null): TextProvider
    {
        $provider = $this->textProvider($name);

        return $this->hasFakeGatewayFor($agent)
            ? (clone $provider)->useTextGateway($this->fakeGatewayFor($agent))
            : $provider;
    }

    /**
     * Get a provider instance by name.
     */
    public function transcriptionProvider(?string $name = null): TranscriptionProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof TranscriptionProvider) {
                throw new LogicException('Provider ['. $instance::class .'] does not support transcription generation.');
            }
        });
    }

    /**
     * Get a transcription provider instance, using a fake gateway if transcriptions are faked.
     */
    public function fakeableTranscriptionProvider(?string $name = null): TranscriptionProvider
    {
        $provider = $this->transcriptionProvider($name);

        return $this->transcriptionsAreFaked()
            ? (clone $provider)->useTranscriptionGateway($this->fakeTranscriptionGateway())
            : $provider;
    }

    /**
     * Get a file provider instance by name.
     */
    public function fileProvider(?string $name = null): FileProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof FileProvider) {
                throw new LogicException('Provider ['. $instance::class .'] does not support file management.');
            }
        });
    }

    /**
     * Get a file provider instance, using a fake gateway if files are faked.
     */
    public function fakeableFileProvider(?string $name = null): FileProvider
    {
        $provider = $this->fileProvider($name);

        return $this->filesAreFaked()
            ? (clone $provider)->useFileGateway($this->fakeFileGateway())
            : $provider;
    }

    /**
     * Get a store provider instance by name.
     */
    public function storeProvider(?string $name = null): StoreProvider
    {
        return tap($this->instance($name), function ($instance) {
            if (! $instance instanceof StoreProvider) {
                throw new LogicException('Provider ['. $instance::class .'] does not support store management.');
            }
        });
    }

    /**
     * Get a store provider instance, using a fake gateway if stores are faked.
     */
    public function fakeableStoreProvider(?string $name = null): StoreProvider
    {
        $provider = $this->storeProvider($name);

        return $this->storesAreFaked()
            ? (clone $provider)->useStoreGateway($this->fakeStoreGateway())
            : $provider;
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
     * Create a Cohere powered instance.
     */
    public function createCohereDriver(array $config): CohereProvider
    {
        return new CohereProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create a DeepSeek powered instance.
     */
    public function createDeepseekDriver(array $config): DeepSeekProvider
    {
        return new DeepSeekProvider(
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
     * Create a Jina powered instance.
     */
    public function createJinaDriver(array $config): JinaProvider
    {
        return new JinaProvider(
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create a Mistral AI powered instance.
     */
    public function createMistralDriver(array $config): MistralProvider
    {
        return new MistralProvider(
            new PrismGateway($this->app['events']),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create an Ollama powered instance.
     */
    public function createOllamaDriver(array $config): OllamaProvider
    {
        return new OllamaProvider(
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
     * Create an OpenRouter powered instance.
     */
    public function createOpenrouterDriver(array $config): OpenRouterProvider
    {
        return new OpenRouterProvider(
            new PrismGateway($this->app['events']),
            $config,
            $this->app->make(Dispatcher::class)
        );
    }

    /**
     * Create a VoyageAI powered instance.
     */
    public function createVoyageaiDriver(array $config): VoyageAiProvider
    {
        return new VoyageAiProvider(
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
        $config = $this->app['config']->get(
            'ai.providers.'.$name, ['driver' => $name],
        );

        $config['name'] = $name;

        return $config;
    }
}
