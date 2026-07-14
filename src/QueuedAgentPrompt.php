<?php

namespace Laravel\Ai;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\Concerns\HasTranscript;
use Laravel\Ai\Prompts\Transcript;

class QueuedAgentPrompt
{
    use HasTranscript;

    /** @var Message[]|string */
    public readonly array|string $prompt;

    /**
     * @param  Message[]|string  $prompt
     */
    public function __construct(
        public Agent $agent,
        array|string $prompt,
        public Collection|array $attachments,
        public Lab|array|string|null $provider,
        public ?string $model
    ) {
        $this->prompt = is_array($prompt) ? Transcript::normalize($prompt) : $prompt;
    }

    /**
     * Determine if the prompt contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->text(), $string);
    }
}
