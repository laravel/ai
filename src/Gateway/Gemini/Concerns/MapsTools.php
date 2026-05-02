<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\ToolNameResolver;
use RuntimeException;

trait MapsTools
{
    /**
     * Map the given tools to Gemini tool definitions.
     */
    protected function mapTools(array $tools, Provider $provider): array
    {
        $functionDeclarations = [];
        $providerTools = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                $providerTool = $this->mapProviderTool($tool, $provider);

                if (filled($providerTool)) {
                    $providerTools[] = $providerTool;
                }
            } elseif ($tool instanceof Tool) {
                $functionDeclarations[] = $this->mapTool($tool);
            }
        }

        $toolsArray = [];

        if (filled($functionDeclarations)) {
            $toolsArray[] = ['function_declarations' => $functionDeclarations];
        }

        foreach ($providerTools as $providerTool) {
            $toolsArray[] = $providerTool;
        }

        return $toolsArray;
    }

    /**
     * Map a regular tool to a Gemini function declaration.
     */
    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $definition = [
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string) $tool->description(),
        ];

        if (filled($schema)) {
            $schemaArray = (new ObjectSchema($schema))->toSchema();

            $definition['parameters'] = Arr::except(
                $this->convertNullableTypes([
                    'type' => 'object',
                    'properties' => $schemaArray['properties'] ?? [],
                    'required' => $schemaArray['required'] ?? [],
                ]),
                ['additionalProperties'],
            );
        }

        return $definition;
    }

    /**
     * Recursively convert JSON Schema nullable types to OpenAPI-style for Gemini.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function convertNullableTypes(array $schema): array
    {
        if (is_array($schema['type'] ?? null) && in_array('null', $schema['type'], true)) {
            $remaining = array_values(array_diff($schema['type'], ['null']));

            if (count($remaining) === 1) {
                $schema['type'] = $remaining[0];
                $schema['nullable'] = true;
            }
        }

        if (isset($schema['properties'])) {
            $schema['properties'] = Arr::map(
                $schema['properties'],
                fn ($property) => $this->convertNullableTypes($property),
            );
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->convertNullableTypes($schema['items']);
        }

        return $schema;
    }

    /**
     * Map a provider tool to a Gemini provider tool definition.
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof FileSearch => $this->mapFileSearchTool($tool, $provider),
            $tool instanceof WebFetch => $this->mapWebFetchTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => [],
        };
    }

    /**
     * Map a file search tool to a Gemini file search definition.
     */
    protected function mapFileSearchTool(FileSearch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsFileSearch) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support file search.');
        }

        return [
            'fileSearch' => $provider->fileSearchToolOptions($tool),
        ];
    }

    /**
     * Map a web fetch tool to a Gemini URL context definition.
     */
    protected function mapWebFetchTool(WebFetch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsWebFetch) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support web fetch.');
        }

        return [
            'url_context' => (object) $provider->webFetchToolOptions($tool),
        ];
    }

    /**
     * Map a web search tool to a Gemini Google Search definition.
     */
    protected function mapWebSearchTool(WebSearch $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsWebSearch) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support web search.');
        }

        return [
            'google_search' => (object) $provider->webSearchToolOptions($tool),
        ];
    }
}
