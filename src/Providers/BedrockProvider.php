<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\Bedrock\BedrockImageGateway;
use Laravel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Laravel\Ai\Gateway\Bedrock\BedrockTranscriptionGateway;

/**
 * AWS Bedrock provider for Laravel AI SDK.
 *
 * Supports multiple AI capabilities through AWS Bedrock:
 * - Text generation (50+ models: Claude, Titan, Llama, Mistral, etc.) with streaming support
 * - Tool/function calling with automatic multi-turn conversation handling
 * - Embeddings (Titan Embed, Cohere Embed)
 * - Image generation (Nova Canvas, Titan Image, Stability AI models)
 * - Speech transcription (Nova Sonic, Voxtral) with diarization
 *
 * Authentication methods:
 * 1. IAM credentials (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_SESSION_TOKEN)
 * 2. Bearer token (AWS_BEARER_TOKEN_BEDROCK)
 * 3. Default AWS credential chain (environment, ~/.aws/credentials, IAM roles)
 *
 * Tool calling features:
 * - Automatic tool execution loop with maxSteps configuration
 * - Full conversation context maintained across tool calls
 * - Tool invocation callbacks for monitoring and debugging
 *
 * Note: Audio synthesis (text-to-speech) is not currently supported via AWS Bedrock API.
 * Use Amazon Polly, ElevenLabs, or OpenAI providers for TTS capabilities.
 */
class BedrockProvider extends Provider implements EmbeddingProvider, ImageProvider, TextProvider, TranscriptionProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesImages;
    use Concerns\GeneratesText;
    use Concerns\GeneratesTranscriptions;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasImageGateway;
    use Concerns\HasTextGateway;
    use Concerns\HasTranscriptionGateway;
    use Concerns\StreamsText;

    public function __construct(
        protected array $config,
        protected Dispatcher $events
    ) {}

    /**
     * Get the credentials for the underlying AI provider.
     */
    public function providerCredentials(): array
    {
        return array_filter([
            'access_key_id' => $this->config['access_key_id'] ?? null,
            'secret_access_key' => $this->config['secret_access_key'] ?? null,
            'session_token' => $this->config['session_token'] ?? null,
        ]);
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     */
    public function additionalConfiguration(): array
    {
        return array_filter([
            'region' => $this->config['region'] ?? 'us-east-1',
            'use_default_credential_provider' => $this->config['use_default_credential_provider'] ?? true,
        ]);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'us.anthropic.claude-sonnet-4-5-20250929-v1:0';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'us.anthropic.claude-haiku-4-5-20250929-v1:0';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'us.anthropic.claude-opus-4-6-20250929-v1:0';
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'amazon.titan-embed-text-v2:0';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1024;
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'amazon.nova-canvas-v1:0';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array
    {
        return [
            'quality' => $quality ?? 'standard',
            'size' => match ($size) {
                '1:1' => '1024x1024',
                '2:3' => '768x1152',
                '3:2' => '1152x768',
                null => '1024x1024',
                default => $size,
            },
        ];
    }

    /**
     * Get the name of the default transcription (STT) model.
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'amazon.nova-sonic-v1:0';
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= new BedrockTextGateway;
    }

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new BedrockTextGateway;
    }

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new BedrockImageGateway;
    }

    /**
     * Get the provider's transcription gateway.
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new BedrockTranscriptionGateway;
    }
}
