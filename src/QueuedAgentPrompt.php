<?php

namespace Laravel\Ai;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;

class QueuedAgentPrompt
{
    public function __construct(
        public Agent $agent,
        public string $prompt,
        public Collection|array $attachments,
        public array|string|null $provider,
        public ?string $model
    ) {}
}
