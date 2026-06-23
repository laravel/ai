<?php

namespace Laravel\Ai\Gateway\Ollama\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\Data\ToolCall;

trait ExecutesWebTools
{
    /**
     * Resolve a tool call to its result, or null when no matching tool is registered.
     */
    protected function resolveToolResult(ToolCall $toolCall, array $tools, Provider $provider, ?int $timeout): ?string
    {
        if ($webTool = $this->findProviderWebTool($toolCall->name, $tools)) {
            return $this->executeWebTool($webTool, $toolCall->arguments, $provider, $timeout);
        }

        $tool = $this->findTool($toolCall->name, $tools);

        return $tool === null
            ? null
            : $this->executeTool($tool, $toolCall->arguments);
    }

    /**
     * Find a provider web tool matching the given tool call name.
     */
    protected function findProviderWebTool(string $name, array $tools): ?ProviderTool
    {
        foreach ($tools as $tool) {
            if ($name === 'web_search' && $tool instanceof WebSearch) {
                return $tool;
            }

            if ($name === 'web_fetch' && $tool instanceof WebFetch) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Execute a web tool against Ollama's hosted web search API.
     */
    protected function executeWebTool(ProviderTool $tool, array $arguments, Provider $provider, ?int $timeout = null): string
    {
        $key = $provider->providerCredentials()['key'] ?? null;

        if (blank($key)) {
            throw new AiException('Ollama web search requires an API key. Set the "key" configuration value for the Ollama provider.');
        }

        $client = Http::baseUrl($this->webSearchBaseUrl($provider))
            ->withToken($key)
            ->timeout($timeout ?? 60)
            ->throw();

        [$endpoint, $body] = $tool instanceof WebSearch
            ? ['api/web_search', $this->webSearchRequestBody($tool, $arguments, $provider)]
            : ['api/web_fetch', $this->webFetchRequestBody($arguments)];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $client->post($endpoint, $body),
        );

        $data = $response->json();

        return $data === null ? '{}' : (json_encode($data) ?: '{}');
    }

    /**
     * Build the request body for an Ollama web search call.
     */
    protected function webSearchRequestBody(WebSearch $tool, array $arguments, Provider $provider): array
    {
        $options = $provider instanceof SupportsWebSearch
            ? $provider->webSearchToolOptions($tool)
            : [];

        $maxResults = $options['max_results'] ?? $arguments['max_results'] ?? null;

        return Arr::whereNotNull([
            'query' => $arguments['query'] ?? null,
            'max_results' => is_null($maxResults) ? null : min((int) $maxResults, 10),
        ]);
    }

    /**
     * Build the request body for an Ollama web fetch call.
     */
    protected function webFetchRequestBody(array $arguments): array
    {
        return Arr::whereNotNull([
            'url' => $arguments['url'] ?? null,
        ]);
    }

    /**
     * Get the base URL for Ollama's hosted web search API.
     */
    protected function webSearchBaseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['web_search_url'] ?? 'https://ollama.com', '/');
    }
}
