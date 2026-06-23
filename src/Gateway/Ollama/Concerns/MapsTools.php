<?php

namespace Laravel\Ai\Gateway\Ollama\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\ToolNameResolver;
use RuntimeException;

trait MapsTools
{
    /**
     * Map the given tools to Ollama function definitions.
     */
    protected function mapTools(array $tools, Provider $provider): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                $mapped[] = $this->mapProviderTool($tool, $provider);
            } elseif ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a provider tool to an Ollama function definition.
     *
     * Ollama exposes web search and web fetch as client-executed functions
     * backed by its hosted API rather than as native server-side tools.
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            $tool instanceof WebFetch => $this->mapWebFetchTool($tool, $provider),
            default => throw new RuntimeException('Ollama does not support ['.class_basename($tool).'] provider tools.'),
        };
    }

    /**
     * Map a web search tool to an Ollama function definition.
     */
    protected function mapWebSearchTool(WebSearch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsWebSearch) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support web search.');
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => 'web_search',
                'description' => 'Search the web for current information. Returns a list of results with titles, URLs, and content snippets.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The search query.',
                        ],
                        'max_results' => [
                            'type' => 'integer',
                            'description' => 'The maximum number of results to return.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * Map a web fetch tool to an Ollama function definition.
     */
    protected function mapWebFetchTool(WebFetch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsWebFetch) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support web fetch.');
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => 'web_fetch',
                'description' => 'Fetch the contents of a web page by URL. Returns the page title, content, and links.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'The URL of the web page to fetch.',
                        ],
                    ],
                    'required' => ['url'],
                ],
            ],
        ];
    }

    /**
     * Map a regular tool to an Ollama function definition.
     */
    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $schemaArray = filled($schema)
            ? (new ObjectSchema($schema))->toSchema()
            : [];

        return [
            'type' => 'function',
            'function' => [
                'name' => ToolNameResolver::resolve($tool),
                'description' => (string) $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $schemaArray['properties'] ?? (object) [],
                    'required' => $schemaArray['required'] ?? [],
                ],
            ],
        ];
    }
}
