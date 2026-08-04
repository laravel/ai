<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Collection;

class AgentPromptContext
{
    public ?string $processedPrompt = null;

    /** @var Collection<int, mixed>|null */
    public ?Collection $processedAttachments = null;

    /**
     * Record the prompt after agent middleware has been applied.
     */
    public function recordProcessedPrompt(AgentPrompt $prompt): void
    {
        $this->processedPrompt = $prompt->prompt;
        $this->processedAttachments = $prompt->attachments;
    }
}
