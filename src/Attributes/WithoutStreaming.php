<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use InvalidArgumentException;
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
final class WithoutStreaming
{
    /**
     * The stream event classes that should not be streamed to the client.
     *
     * @var array<int, class-string<StreamEvent>>
     */
    public array $events;

    /**
     * Create a new attribute instance.
     *
     * @param  class-string<StreamEvent>  ...$events
     */
    public function __construct(string ...$events)
    {
        foreach ($events as $event) {
            if (! is_subclass_of($event, StreamEvent::class)) {
                throw new InvalidArgumentException("[{$event}] is not a valid ".StreamEvent::class.' to exclude from streaming.');
            }
        }

        $this->events = $events;
    }

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
     * Get the names of the agent's tools that are marked without events, so their calls and results stay off the wire.
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

            $attributes = (new ReflectionClass($tool instanceof AgentTool ? $tool->agent() : $tool))->getAttributes(self::class);

            if ($attributes !== [] && $attributes[0]->newInstance()->events === []) {
                $names[] = ToolNameResolver::resolve($tool);
            }
        }

        return $names;
    }

    /**
     * Get the stream event classes that should not be streamed to the client for the target agent.
     *
     * @return array<int, class-string<StreamEvent>>
     */
    public static function eventsFor(?object $target): array
    {
        if ($target === null) {
            return [];
        }

        $attributes = (new ReflectionClass($target))->getAttributes(self::class);

        if ($attributes === []) {
            return [];
        }

        return $attributes[0]->newInstance()->events;
    }
}
