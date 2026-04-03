<?php

namespace Laravel\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Stringable;
use Throwable;

class AgentTool implements Tool
{
    public function __construct(protected Agent $agent) {}

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return method_exists($this->agent, 'name')
            ? $this->agent->name()
            : class_basename($this->agent);
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return method_exists($this->agent, 'description')
            ? $this->agent->description()
            : (string) $this->agent->instructions();
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        try {
            return $this->agent->prompt((string) $request['task'])->text;
        } catch (Throwable $e) {
            return 'Agent failed: '.$e->getMessage();
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('The task to delegate to this agent.')->required(),
        ];
    }

    /**
     * Get the underlying agent instance.
     */
    public function agent(): Agent
    {
        return $this->agent;
    }
}
