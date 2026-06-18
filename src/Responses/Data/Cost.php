<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class Cost implements Arrayable, JsonSerializable
{
    public function __construct(
        public float $input = 0.0,
        public float $output = 0.0,
        public float $cacheRead = 0.0,
        public float $cacheWrite = 0.0,
        public float $reasoning = 0.0,
        public string $currency = 'USD',
        public bool $known = true,
    ) {}

    /**
     * Create a cost representing a model with no known pricing.
     */
    public static function unknown(string $currency = 'USD'): self
    {
        return new self(currency: $currency, known: false);
    }

    /**
     * Get the total cost across every component.
     */
    public function total(): float
    {
        return $this->input + $this->output + $this->cacheRead + $this->cacheWrite + $this->reasoning;
    }

    /**
     * Determine if the cost was computed from known pricing.
     */
    public function isKnown(): bool
    {
        return $this->known;
    }

    /**
     * Add the given cost to the current cost and return a new instance.
     */
    public function add(Cost $other): self
    {
        return new self(
            $this->input + $other->input,
            $this->output + $other->output,
            $this->cacheRead + $other->cacheRead,
            $this->cacheWrite + $other->cacheWrite,
            $this->reasoning + $other->reasoning,
            $this->currency,
            $this->known && $other->known,
        );
    }

    /**
     * Get a human-readable representation of the total cost.
     */
    public function format(int $precision = 4): string
    {
        $amount = number_format($this->total(), $precision);

        return $this->currency === 'USD' ? '$'.$amount : $amount.' '.$this->currency;
    }

    /**
     * Get the per-component cost breakdown.
     *
     * @return array<string, float>
     */
    public function breakdown(): array
    {
        return [
            'input' => $this->input,
            'output' => $this->output,
            'cache_read' => $this->cacheRead,
            'cache_write' => $this->cacheWrite,
            'reasoning' => $this->reasoning,
        ];
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'currency' => $this->currency,
            'known' => $this->known,
            ...$this->breakdown(),
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
