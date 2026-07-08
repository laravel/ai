<?php

namespace Laravel\Ai\Gateway\OpenAi\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\ProviderToolSearch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\ToolNameResolver;
use RuntimeException;

trait MapsTools
{
    /**
     * Map the given tools to OpenAI function definitions.
     */
    protected function mapTools(array $tools, Provider $provider, string $model = '', bool $stateless = false): array
    {
        $mapped = [];
        $searchIndex = null;

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderToolSearch) {
                if (blank($tool->tools)) {
                    continue;
                }

                $this->guardToolSearchSupport($provider, $model, $stateless);

                if ($searchIndex === null) {
                    $searchIndex = count($mapped);
                    $mapped[$searchIndex] = ['type' => 'tool_search'];
                }

                $mapped[$searchIndex] = [...$mapped[$searchIndex], ...array_diff_key($tool->providerOptions(Lab::OpenAI), ['type' => true])];

                foreach ($tool->tools as $deferred) {
                    $mapped[] = $this->mapTool($deferred, defer: true);
                }
            } elseif ($tool instanceof ProviderTool) {
                $mapped[] = $this->mapProviderTool($tool, $provider);
            } elseif ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Ensure the provider and model support hosted tool search.
     */
    protected function guardToolSearchSupport(Provider $provider, string $model, bool $stateless = false): void
    {
        if (! $provider instanceof SupportsToolSearch || ! $provider->supportsToolSearch($model)) {
            throw new RuntimeException(
                "Provider [{$provider->name()}] does not support tool search for model [{$model}]."
            );
        }

        if ($stateless) {
            throw new RuntimeException(
                "Provider [{$provider->name()}] does not support tool search when response storage is disabled (store=false)."
            );
        }
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
     * Map a provider tool to an OpenAI provider tool definition.
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof FileSearch => $this->mapFileSearchTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => throw new RuntimeException('Provider ['.$provider->name().'] does not support the ['.class_basename($tool).'] tool.'),
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
