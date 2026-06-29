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
        if ($target === null) {
            return true;
        }

        $attributes = (new ReflectionClass($target))->getAttributes(self::class);

        if ($attributes === []) {
            return true;
        }

        return ! in_array($event::class, $attributes[0]->newInstance()->events, true);
    }
}
