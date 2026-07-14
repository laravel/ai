<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\ToolChoice;
use Laravel\Ai\Tools\ToolNameResolver;
use RuntimeException;

trait MapsChatCompletionTools
{
    /**
     * Map the given tools to Chat Completions function definitions.
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
     * Map a provider tool to a Chat Completions tool definition.
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        throw new RuntimeException(Str::of(class_basename($this))->before('Gateway').' does not support ['.class_basename($tool).'] provider tools.');
    }

    /**
     * Map a regular tool to a Chat Completions function definition.
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
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Map a tool choice to the Chat Completions tool_choice shape.
     *
     * @return string|array<string, mixed>
     */
    protected function mapToolChoice(ToolChoice $choice): string|array
    {
        return match ($choice->mode) {
            ToolChoice::auto, ToolChoice::none, ToolChoice::required => $choice->mode,
            ToolChoice::tool => [
                'type' => 'function',
                'function' => [
                    'name' => $choice->toolName,
                ],
            ],
        };
    }
}
