<?php

namespace Laravel\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\SubAgent;
use Laravel\Ai\Contracts\Tool;
use Stringable;

class SubAgentTool implements Tool
{
    public function __construct(
        protected SubAgent $subAgent,
        protected ?string $provider = null,
        protected ?string $model = null,
    ) {}

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return $this->subAgent->name();
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return $this->subAgent->description();
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return $this->subAgent->prompt(
            (string) $request->string('task'),
            provider: $this->provider,
            model: $this->model,
        )->text;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->required(),
        ];
    }
}
