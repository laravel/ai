<?php

namespace Laravel\Ai\Gateway;

use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Usage;

/** @internal */
final class SingleTurnStreamEnd
{
    /** @param  array<int, array<string, mixed>>  $providerContentBlocks */
    public function __construct(
        public readonly FinishReason $reason,
        public readonly Usage $usage,
        public readonly ?string $responseId = null,
        public readonly array $providerContentBlocks = [],
    ) {}
}
