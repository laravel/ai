<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;

class UrlCitation extends Citation implements Arrayable, JsonSerializable
{
    /** @var Collection<int, array{start: int, end: int}> */
    public Collection $ranges;

    public function __construct(
        public string $url,
        ?string $title = null,
        public ?int $startIndex = null,
        public ?int $endIndex = null,
    ) {
        parent::__construct($title);

        $this->ranges = new Collection;

        $this->addRange($startIndex, $endIndex);
    }

    /**
     * Add a character range of the response text that this citation supports.
     */
    public function addRange(?int $startIndex, ?int $endIndex): void
    {
        if ($startIndex === null || $endIndex === null) {
            return;
        }

        if ($this->ranges->contains(fn (array $range): bool => $range['start'] === $startIndex && $range['end'] === $endIndex)) {
            return;
        }

        $this->ranges[] = ['start' => $startIndex, 'end' => $endIndex];

        if ($this->ranges->count() === 1) {
            $this->startIndex = $startIndex;
            $this->endIndex = $endIndex;
        }
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'start_index' => $this->startIndex,
            'end_index' => $this->endIndex,
            'ranges' => $this->ranges->values()->all(),
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
