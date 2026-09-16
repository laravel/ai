<?php

namespace Workbench\App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ConvertTemperature implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Convert a temperature between Celsius and Fahrenheit. Always use this instead of converting yourself.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $temperature = $request->float('temperature');

        return match ($request->string('to')->toString()) {
            'fahrenheit' => round($temperature * 9 / 5 + 32, 1).'°F',
            default => round(($temperature - 32) * 5 / 9, 1).'°C',
        };
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'temperature' => $schema->number()->description('The temperature to convert')->required(),
            'to' => $schema->string()->enum(['celsius', 'fahrenheit'])->description('The unit to convert to')->required(),
        ];
    }
}
