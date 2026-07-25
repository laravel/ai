<?php

namespace Laravel\Ai\Responses;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Meta;

class TokenCountResponse implements Arrayable, JsonSerializable
{
    /**
     * Create a new token count response instance.
     */
    public function __construct(public int $tokens, public Meta $meta) {}

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'tokens' => $this->tokens,
            'meta' => $this->meta,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
