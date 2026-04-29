<?php

namespace Laravel\Ai\Gateway\Infomaniak\Concerns;

use Laravel\Ai\Contracts\Tool;

trait MapsTools
{
    protected function mapTools(array $tools): array
    {
        return array_values(array_map(
            fn (Tool $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parameters(),
                ],
            ],
            array_filter($tools, fn ($tool) => $tool instanceof Tool)
        ));
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
