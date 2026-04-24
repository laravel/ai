<?php

namespace Laravel\Ai\Responses;

trait ProvidesStructuredResponse
{
    public array $structured;

    /**
     * Determine if an item exists at an offset.
     */
    public function offsetExists(mixed $offset): bool
    {
        if (isset($this->structured[$offset])) {
            return true;
        }

        foreach (['response', 'result', 'data', 'output'] as $wrapper) {
            if (isset($this->structured[$wrapper]) && is_array($this->structured[$wrapper]) && array_key_exists($offset, $this->structured[$wrapper])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get an item at a given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (array_key_exists($offset, $this->structured)) {
            return $this->structured[$offset];
        }

        foreach (['response', 'result', 'data', 'output'] as $wrapper) {
            if (isset($this->structured[$wrapper]) && is_array($this->structured[$wrapper]) && array_key_exists($offset, $this->structured[$wrapper])) {
                return $this->structured[$wrapper][$offset];
            }
        }

        return null;
    }

    /**
     * Set the item at a given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            $this->structured[] = $value;
        } else {
            $this->structured[$offset] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->structured[$offset]);
    }
}
