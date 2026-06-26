<?php

namespace Laravel\Ai\Contracts;

interface HasMetadata
{
    /**
     * Return agent-specific metadata to attach to lifecycle observers.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array;
}
