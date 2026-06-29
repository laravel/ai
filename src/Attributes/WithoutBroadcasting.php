<?php

namespace Laravel\Ai\Attributes;

use Attribute;
use Laravel\Ai\Streaming\Events\StreamEvent;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
final class WithoutBroadcasting
{
    /**
     * The stream event classes that should not be broadcast.
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
        $this->events = $events;
    }

    /**
     * Determine if the given stream event should be broadcast for the target agent.
     */
    public static function allows(?object $target, StreamEvent $event): bool
    {
        return ! in_array($event::class, self::eventsFor($target), true);
    }

    /**
     * Get the stream event classes that should not be broadcast for the target agent.
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
