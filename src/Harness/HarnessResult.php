<?php

namespace Laravel\Ai\Harness;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

class HarnessResult
{
    public function __construct(
        public string $text,
        public Usage $usage,
        public Meta $meta,
        public string $sessionId,
        public Collection $toolCalls = new Collection,
        public Collection $pendingApprovals = new Collection,
        public string $finishReason = 'stop',
    ) {}
}
