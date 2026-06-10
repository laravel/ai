<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Attributes\Deferred;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\ToolNameResolver;
use LogicException;
use RuntimeException;

trait MapsTools
{
    /**
     * Map the given tools to Anthropic tool definitions.
     */
    protected function mapTools(array $tools, Provider $provider, string $model = '', ?TextGenerationOptions $options = null): array
    {
        $searchActive = $this->toolSearchActive($provider, $model, $options);

        $mapped = [];
        $toolCount = 0;
        $deferredCount = 0;

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                $mapped[] = $this->mapProviderTool($tool, $provider);
            } elseif ($tool instanceof Tool) {
                $defer = $searchActive && Deferred::isAppliedTo($tool);
                $mapped[] = $this->mapTool($tool, $defer);
                $toolCount++;
                $deferredCount += $defer ? 1 : 0;
            }
        }

        if ($searchActive && $deferredCount > 0) {
            if ($deferredCount === $toolCount) {
                throw new LogicException(
                    'Anthropic tool search requires at least one non-deferred tool.'
                );
            }

            $strategy = $options?->toolSearchStrategy === 'bm25' ? 'bm25' : 'regex';

            array_unshift($mapped, [
                'type' => "tool_search_tool_{$strategy}_20251119",
                'name' => "tool_search_tool_{$strategy}",
            ]);
        }

        return $mapped;
    }

    /**
     * Map a regular tool to an Anthropic tool definition.
     */
    protected function mapTool(Tool $tool, bool $defer = false): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $inputSchema = ['type' => 'object', 'properties' => (object) []];

        if (filled($schema)) {
            $schemaArray = (new ObjectSchema($schema))->toSchema();

            $inputSchema['properties'] = (object) ($schemaArray['properties'] ?? []);
            $inputSchema['required'] = $schemaArray['required'] ?? [];
        }

        $definition = [
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string) $tool->description(),
            'input_schema' => $inputSchema,
        ];

        if ($defer) {
            $definition['defer_loading'] = true;
        }

        return $definition;
    }

    /**
     * Determine whether hosted tool search is active for this request.
     */
    protected function toolSearchActive(Provider $provider, string $model, ?TextGenerationOptions $options): bool
    {
        return $options?->toolSearchStrategy !== null
            && $provider instanceof SupportsToolSearch
            && $provider->supportsToolSearch($model);
    }

    /**
     * Map a provider tool to an Anthropic provider tool definition.
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof WebFetch => $this->mapWebFetchTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => throw new LogicException('Provider tool ['.get_class($tool).'] is not supported by Anthropic.'),
        };
    }

    /**
     * Map a web fetch tool to an Anthropic server-side tool definition.
     */
    protected function mapWebFetchTool(WebFetch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsWebFetch) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support web fetch.');
        }

        return [
            'type' => 'web_fetch_20250910',
            'name' => 'web_fetch',
            ...$provider->webFetchToolOptions($tool),
        ];
    }

    /**
     * Map a web search tool to an Anthropic server-side tool definition.
     */
    protected function mapWebSearchTool(WebSearch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsWebSearch) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support web search.');
        }

        return [
            'type' => 'web_search_20250305',
            'name' => 'web_search',
            ...$provider->webSearchToolOptions($tool),
        ];
    }
}
