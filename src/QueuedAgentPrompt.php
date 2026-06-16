<?php

namespace Laravel\Ai;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;

class QueuedAgentPrompt
{
    public function __construct(
        public Agent $agent,
        public string $prompt,
        public Collection|array $attachments,
        public Lab|array|string|null $provider,
        public ?string $model
    ) {}

    /**
     * Determine if the prompt contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->prompt, $string);
    }
}
