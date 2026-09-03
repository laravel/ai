<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class Voice implements Arrayable, JsonSerializable
{
    /**
     * @param  array<int, string>  $languages
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $gender = null,
        public readonly array $languages = [],
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'gender' => $this->gender,
            'languages' => $this->languages,
        ];
    }

    /**
     * Convert the instance into something JSON serializable.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
