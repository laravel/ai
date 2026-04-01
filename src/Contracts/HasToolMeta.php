<?php

namespace Laravel\Ai\Contracts;

interface HasToolMeta
{
    /**
     * Get the UI metadata for this tool.
     */
    public function toolMeta(): array;
}
