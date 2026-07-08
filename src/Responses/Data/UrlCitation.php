<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use JsonSerializable;

class UrlCitation extends Citation implements Arrayable, JsonSerializable
{
    /** @var Collection<int, array{0: int, 1: int}> */
    public Collection $ranges;

    public function __construct(
        public string $url,
        ?string $title = null,
        public ?int $startIndex = null,
        public ?int $endIndex = null,
        /**
         * Whether startIndex/endIndex are byte offsets rather than character offsets.
         * Used by providers that report grounding positions in bytes (e.g. Gemini Search grounding).
         */
        public bool $isByteOffset = false,
    ) {
        parent::__construct($title);

        $this->ranges = ($startIndex !== null && $endIndex !== null)
            ? collect([[$startIndex, $endIndex]])
            : collect();
    }

    /**
     * Add a text range for this citation.
     * Duplicate ranges are silently ignored.
     */
    public function addRange(?int $startIndex, ?int $endIndex): void
    {
        if ($startIndex === null || $endIndex === null) {
            return;
        }

        if ($this->ranges->contains(fn ($r) => $r[0] === $startIndex && $r[1] === $endIndex)) {
            return;
        }

        $this->ranges[] = [$startIndex, $endIndex];

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
            'byte_offset' => $this->isByteOffset,
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
