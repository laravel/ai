<?php

namespace Tests\Feature\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RandomNumberGenerator implements Tool
{
    public static $usedAgent;

    public function __construct(public bool $throwsException = false) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'This tool can be used to generate cryptographically secure random numbers.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        static::$usedAgent = $request->agent();

        if ($this->throwsException) {
            throw new \Exception('Forced to throw exception.');
        }

        return random_int($request['min'], $request['max']);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'min' => $schema->integer()->description('The minimum value of the random number')->default(1)->required(),
            'max' => $schema->integer()->description('The maximum value of the random number')->default(100)->required(),
        ];
    }
}
