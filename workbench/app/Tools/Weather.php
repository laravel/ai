<?php

namespace Workbench\App\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class Weather implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Get the current weather for a city. Reports the temperature in Celsius.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $city = $request->string('city')->toString();

        // Deterministic pseudo-weather so runs are reproducible per city...
        $seed = crc32(strtolower($city));

        $temperature = 5 + ($seed % 30);
        $condition = ['sunny', 'cloudy', 'rainy', 'windy', 'stormy'][$seed % 5];

        return "The weather in {$city} is {$condition} at {$temperature}°C.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->description('The city to get the weather for')->required(),
        ];
    }
}
