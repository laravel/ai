<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class UrlCitation extends Citation implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $url,
        ?string $title = null,
    ) {
        parent::__construct($title);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
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
