<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Providers\SupportsCodeExecution;
use Laravel\Ai\Contracts\Providers\SupportsWebFetch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\ToolNameResolver;
use LogicException;
use RuntimeException;

trait MapsTools
{
    /**
     * Anthropic's dated version identifier for the hosted tool search tool.
     */
    protected const TOOL_SEARCH_TOOL_VERSION = '20251119';

    /**
     * Map the given tools to Anthropic tool definitions.
     */
    protected function mapTools(array $tools, Provider $provider): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ToolSearch) {
                if (blank($tool->tools)) {
                    continue;
                }

                $strategy = $tool->strategy === 'bm25' ? 'bm25' : 'regex';

                $mapped[] = [
                    'type' => "tool_search_tool_{$strategy}_".self::TOOL_SEARCH_TOOL_VERSION,
                    'name' => "tool_search_tool_{$strategy}",
                    ...array_diff_key($tool->providerOptions(Lab::Anthropic), ['strategy' => true, 'type' => true, 'name' => true]),
                ];

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
     * Map a provider tool to an Anthropic provider tool definition.
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof CodeExecution => $this->mapCodeExecutionTool($tool, $provider),
            $tool instanceof WebFetch => $this->mapWebFetchTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => throw new LogicException('Provider tool ['.$tool::class.'] is not supported by Anthropic.'),
        };
    }

    /**
     * Map a code execution tool to an Anthropic server-side tool definition.
     */
    protected function mapCodeExecutionTool(CodeExecution $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsCodeExecution) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support code execution.');
        }

        return [
            'type' => 'code_execution_20260120',
            'name' => 'code_execution',
            ...$provider->codeExecutionToolOptions($tool),
        ];
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
