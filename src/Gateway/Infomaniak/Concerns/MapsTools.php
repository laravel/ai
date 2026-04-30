<?php

namespace Laravel\Ai\Gateway\Infomaniak\Concerns;

use Laravel\Ai\Contracts\Tool;

trait MapsTools
{
    protected function mapTools(array $tools): array
    {
        return array_values(array_map(function (Tool $tool) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => (string) $tool->description(), // Temporary: use description as name
                    'description' => (string) $tool->description(),
                    'parameters' => $tool->schema(new \Illuminate\JsonSchema\JsonSchemaTypeFactory),
                ],
            ];
        }, $tools));
    }

    protected function findTool(string $name, array $tools): ?Tool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof Tool && $tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }
}
