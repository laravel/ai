<?php

namespace Laravel\Ai\Tools;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\InteractsWithData;
use Illuminate\Support\Traits\Macroable;
use Laravel\Ai\Contracts\Agent;

class Request implements Arrayable, ArrayAccess
{
    use Conditionable, InteractsWithData, Macroable;

    public function __construct(
        protected array $arguments = [],
        protected ?Agent $agent = null
    ) {}

    /**
     * Get the agent that is invoking the tool.
     */
    public function agent(): ?Agent
    {
        return $this->agent;
    }

    /**
     * @param  array<array-key, string>|array-key|null  $keys
     * @return array<string, mixed>
     */
    public function all(mixed $keys = null): array
    {
        if (is_null($keys)) {
            return $this->data();
        }

        return array_intersect_key(
            $this->data(),
            array_flip(is_array($keys) ? $keys : func_get_args())
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function data(mixed $key = null, mixed $default = null): mixed
    {
        return is_null($key)
            ? $this->arguments
            : ($this->arguments[$key] ?? $default);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return $this->arguments;
    }

    /**
     * Determine if an item exists at an offset.
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->arguments[$offset]);
    }

    /**
     * Get an item at a given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->arguments[$offset];
    }

    /**
     * Set the item at a given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $this->arguments[] = $value;
        } else {
            $this->arguments[$offset] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->arguments[$offset]);
    }
}
