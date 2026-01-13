<?php

namespace Laravel\Ai\Responses;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class StructuredAgentResponse extends AgentResponse implements Arrayable, ArrayAccess, JsonSerializable
{
    use ProvidesStructuredResponse;

    public function __construct(string $invocationId, array $structured, string $text, Usage $usage, Meta $meta)
    {
        parent::__construct($invocationId, $text, $usage, $meta);

        $this->structured = $structured;
        $this->toolCalls = new Collection;
        $this->toolResults = new Collection;
    }

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return $this->structured;
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
    public function __toString(): string
    {
        return json_encode($this->structured);
    }
}
