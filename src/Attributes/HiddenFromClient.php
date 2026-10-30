<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\ToolNameResolver;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
final class HiddenFromClient
{
    /**
     * Determine if the given event is a call or result of one of the given hidden tools.
     *
     * @param  array<int, string>  $tools
     */
    public static function excludes(array $tools, StreamEvent $event): bool
    {
        return match (true) {
            $event instanceof ToolCall => in_array($event->toolCall->name, $tools, true),
            $event instanceof ToolResult => in_array($event->toolResult->name, $tools, true),
            default => false,
        };
    }

    /**
     * Determine if the given stored tool call was flagged as hidden, or belongs to one of the given hidden tools.
     *
     * @param  array<string, mixed>  $toolCall
     * @param  array<int, string>  $tools
     */
    public static function hides(array $toolCall, array $tools = []): bool
    {
        return ($toolCall['hidden'] ?? false) || in_array($toolCall['name'] ?? null, $tools, true);
    }

    /**
     * Get the names of the given tools, or the agent's tools, that are hidden from the client.
     *
     * @param  iterable<int, mixed>|null  $tools
     * @return array<int, string>
     */
    public static function toolsFor(?object $agent, ?iterable $tools = null): array
    {
        $names = [];

        foreach ($tools ?? ($agent instanceof HasTools ? $agent->tools() : []) as $tool) {
            $tool = $tool instanceof Agent ? new AgentTool($tool) : $tool;

            if (! $tool instanceof Tool) {
                continue;
            }

            $reflection = new ReflectionClass($tool instanceof AgentTool ? $tool->agent() : $tool);

            if ($reflection->getAttributes(self::class) !== []) {
                $names[] = ToolNameResolver::resolve($tool);
            }
        }

        return $names;
    }
}
