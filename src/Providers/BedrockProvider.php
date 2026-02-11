<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;

class BedrockProvider extends Provider implements EmbeddingProvider, TextProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesText;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    /**
     * Get the credentials for the underlying AI provider.
     *
     * The prism-php/bedrock package expects `api_key` (AWS access key ID)
     * and `api_secret` (AWS secret access key) for signing requests.
     */
    public function providerCredentials(): array
    {
        return [
            'key' => $this->config['access_key'] ?? null,
        ];
    }

    /**
     * Get the provider connection configuration other than the driver, key, and name.
     *
     * Passes AWS-specific configuration that the Bedrock Prism provider needs:
     * api_secret, session_token, region, and use_default_credential_provider.
     */
    public function additionalConfiguration(): array
    {
        return array_filter([
            'api_secret' => $this->config['secret_key'] ?? null,
            'session_token' => $this->config['session_token'] ?? null,
            'region' => $this->config['region'] ?? 'us-east-1',
            'use_default_credential_provider' => $this->useDefaultCredentialProvider(),
        ]);
    }

    /**
     * Determine if the default AWS credential provider chain should be used.
     */
    protected function useDefaultCredentialProvider(): bool
    {
        return empty($this->config['access_key']) && empty($this->config['secret_key']);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'anthropic.claude-sonnet-4-5-20250929-v1:0';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return 'anthropic.claude-haiku-4-5-20251001-v1:0';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return 'anthropic.claude-opus-4-6-v1:0';
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return 'amazon.titan-embed-text-v2:0';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return 1024;
    }
}
