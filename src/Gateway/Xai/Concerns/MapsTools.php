<?php

namespace Laravel\Ai\Gateway\Xai\Concerns;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Concerns\ResolvesToolMetadata;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Tools\ToolNameResolver;

trait MapsTools
{
    use ResolvesToolMetadata;

    /**
     * Map the given tools to xAI function definitions.
     */
    protected function mapTools(array $tools, Provider $provider): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                continue;
            }

            if ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a regular tool to an xAI function definition.
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
            'name' => ToolNameResolver::resolve($tool),
            'description' => (string) $tool->description(),
            ...($isRaw ? [] : ['strict' => true]),
            'parameters' => $parameters,
        ];
    }
}
