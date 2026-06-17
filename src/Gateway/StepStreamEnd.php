<?php

namespace Laravel\Ai\Gateway;

use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Usage;

class StepStreamEnd
{
    /** @param  array<int, array<string, mixed>>  $providerContentBlocks */
    public function __construct(
        public readonly FinishReason $reason,
        public readonly Usage $usage,
        public readonly ?string $continuationToken = null,
        public readonly array $providerContentBlocks = [],
    ) {}
}
