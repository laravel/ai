<?php

namespace Laravel\Ai\Gateway\Groq\Concerns;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Concerns\ResolvesToolMetadata;
use Laravel\Ai\Providers\Tools\ProviderTool;
use RuntimeException;

trait MapsTools
{
    use ResolvesToolMetadata;

    /**
     * Map the given tools to Chat Completions function definitions.
     */
    protected function mapTools(array $tools): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                throw new RuntimeException('Groq does not support ['.class_basename($tool).'] provider tools.');
            }

            if ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a regular tool to a Chat Completions function definition.
     */
    protected function mapTool(Tool $tool): array
    {
        $schemaArray = $this->toolSchemaArray($tool);
        $isRaw = $this->toolHasRawSchema($tool);

        if ($isRaw) {
            $parameters = $schemaArray;

            if (($parameters['properties'] ?? []) === []) {
                $parameters['properties'] = (object) [];
            }
        } else {
            $parameters = [
                'type' => 'object',
                'properties' => $schemaArray['properties'] ?? (object) [],
                'required' => $schemaArray['required'] ?? [],
                'additionalProperties' => false,
            ];
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->toolName($tool),
                'description' => (string) $tool->description(),
                'parameters' => $parameters,
            ],
        ];
    }
}
