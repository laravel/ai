<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\AnthropicFileGateway;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;

class AnthropicProvider extends Provider implements FileProvider, SupportsWebFetch, SupportsWebSearch, TextProvider
{
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
            'allowed_domains' => ! empty($fetch->allowedDomains)
                ? $fetch->allowedDomains
                : null,
        ]);
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return array_filter([
            'max_uses' => $search->maxSearches,
            'allowed_domains' => ! empty($search->allowedDomains)
                ? $search->allowedDomains
                : null,
            'user_location' => $search->hasLocation()
                ? array_filter([
                    'type' => 'approximate',
                    'city' => $search->city,
                    'region' => $search->region,
                    'country' => $search->country,
                ])
                : null,
        ]);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'claude-sonnet-4-5-20250929';
    }

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway ??= new AnthropicFileGateway;
    }
}
