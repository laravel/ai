<?php

namespace Laravel\Ai;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Provider;

class QueuedAgentPrompt
{
    public function __construct(
        public Agent $agent,
        public string $prompt,
        public Collection|array $attachments,
        public Lab|array|string|Provider|null $provider,
        public ?string $model
    ) {}
}
