<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\CountsTokens;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\Anthropic\AnthropicFileGateway;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;

class AnthropicProvider extends Provider implements CountsTokens, FileProvider, SupportsWebFetch, SupportsWebSearch, TextProvider
{
    use Concerns\CountsProviderTokens;
    use Concerns\GeneratesText;
    use Concerns\HasFileGateway;
    use Concerns\HasTextGateway;
    use Concerns\ManagesFiles;
    use Concerns\StreamsText;

    /**
     * Get the web fetch tool options for the provider.
     */
    public function webFetchToolOptions(WebFetch $fetch): array
    {
        return array_filter([
            'max_uses' => $fetch->maxSearches ?? 10,
            'allowed_domains' => $fetch->allowedDomains === []
                ? null
                : $fetch->allowedDomains,
        ]);
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return array_filter([
            'max_uses' => $search->maxSearches,
            'allowed_domains' => $search->allowedDomains === []
                ? null
                : $search->allowedDomains,
            'user_location' => $search->hasLocation()
                ? array_filter([
                    'type' => 'approximate',
                    'city' => $search->city,
                    'region' => $search->region,
                    'country' => $search->country,
                ])
                : null,
        ]) + $search->providerOptions(Lab::Anthropic);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'claude-sonnet-5';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'claude-haiku-4-5-20251001';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'claude-opus-4-8';
    }

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway ??= new AnthropicFileGateway;
    }
}
