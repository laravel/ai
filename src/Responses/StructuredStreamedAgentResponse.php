<?php

namespace Laravel\Ai\Responses;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Collection;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;

class StructuredStreamedAgentResponse extends StreamedAgentResponse implements Arrayable, ArrayAccess, Jsonable, JsonSerializable
{
    use ProvidesStructuredResponse;

    /**
     * @param  Collection<int, StreamEvent>  $events
     */
    public function __construct(string $invocationId, Collection $events, Meta $meta)
    {
        parent::__construct($invocationId, $events, $meta);

        $this->structured = $events->whereInstanceOf(StreamEnd::class)->last()?->structured ?? [];
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return $this->structured;
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int  $options
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->structured, $options);
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Get the string representation of the object.
     */
    #[\Override]
    public function __toString(): string
    {
        return (string) json_encode($this->structured);
    }
}
