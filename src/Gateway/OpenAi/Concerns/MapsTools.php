<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Attributes\Deferred;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\ToolNameResolver;
use RuntimeException;

trait MapsTools
{
    /**
     * Map the given tools to OpenAI function definitions.
     */
    protected function mapTools(array $tools, Provider $provider, string $model = '', ?TextGenerationOptions $options = null): array
    {
        $searchActive = $this->toolSearchActive($provider, $model, $options);

        $mapped = [];
        $deferredCount = 0;

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                $mapped[] = $this->mapProviderTool($tool, $provider);
            } elseif ($tool instanceof Tool) {
                $defer = $searchActive && Deferred::isAppliedTo($tool);
                $mapped[] = $this->mapTool($tool, $defer);
                $deferredCount += $defer ? 1 : 0;
            }
        }

        if ($searchActive && $deferredCount > 0) {
            array_unshift($mapped, ['type' => 'tool_search']);
        }

        return $mapped;
    }

    /**
     * Map a regular tool to an OpenAI function definition.
     */
    protected function mapTool(Tool $tool, bool $defer = false): array
    {
        $strict = Strict::isAppliedTo($tool);

        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $schemaArray = filled($schema)
            ? (new ObjectSchema($schema, strict: $strict))->toSchema()
            : [];

        $definition = [
            'type' => 'function',
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string) $tool->description(),
            'strict' => $strict,
            'parameters' => [
                'type' => 'object',
                'properties' => $schemaArray['properties'] ?? (object) [],
                'required' => $schemaArray['required'] ?? [],
                'additionalProperties' => false,
            ],
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
     * Map a provider tool to an OpenAI provider tool definition.
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof FileSearch => $this->mapFileSearchTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => [],
        };
    }

    /**
     * Map a file search tool to an OpenAI file search definition.
     */
    protected function mapFileSearchTool(FileSearch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsFileSearch) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support file search.');
        }

        return [
            'type' => 'file_search',
            ...$provider->fileSearchToolOptions($tool),
        ];
    }

    /**
     * Map a web search tool to an OpenAI web search definition.
     */
    protected function mapWebSearchTool(WebSearch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsWebSearch) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support web search.');
        }

        return [
            'type' => 'web_search',
            ...$provider->webSearchToolOptions($tool),
        ];
    }
}
