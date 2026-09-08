<?php

namespace Laravel\Ai\Gateway\Xai\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Providers\SupportsCodeExecution;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\ToolNameResolver;
use RuntimeException;

trait MapsTools
{
    /**
     * Map the given tools to xAI function definitions.
     */
    protected function mapTools(array $tools, Provider $provider): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                if (filled($providerTool = $this->mapProviderTool($tool, $provider))) {
                    $mapped[] = $providerTool;
                }
            } elseif ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a provider tool to an xAI provider tool definition.
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        return match (true) {
            $tool instanceof CodeExecution => $this->mapCodeExecutionTool($tool, $provider),
            $tool instanceof FileSearch => $this->mapFileSearchTool($tool, $provider),
            $tool instanceof WebSearch => $this->mapWebSearchTool($tool, $provider),
            default => [],
        };
    }

    /**
     * Map a code execution tool to an xAI code interpreter definition.
     */
    protected function mapCodeExecutionTool(CodeExecution $tool, Provider $provider): array
    {
        if (! $provider instanceof SupportsCodeExecution) {
            throw new RuntimeException('Provider ['.$provider->name().'] does not support code execution.');
        }

        return [
            'type' => 'code_interpreter',
            ...$provider->codeExecutionToolOptions($tool),
        ];
    }

    /**
     * Map a file search tool to an xAI file search definition.
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
     * Map a web search tool to an xAI web search definition.
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

    /**
     * Map a regular tool to an xAI function definition.
     */
    protected function mapTool(Tool $tool): array
    {
        $strict = Strict::isAppliedTo($tool);

        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $schemaArray = filled($schema)
            ? (new ObjectSchema($schema, strict: $strict))->toSchema()
            : [];

        return [
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
    }
}
