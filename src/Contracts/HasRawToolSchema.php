<?php

namespace Laravel\Ai\Contracts;

interface HasRawToolSchema
{
    /**
     * Get the raw JSON Schema definition for the tool parameters.
     */
    public function rawSchema(): array;
}
