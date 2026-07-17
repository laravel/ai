<?php

namespace Laravel\Ai;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;

class QueuedAgentPrompt
{
    public Agent $agent;

    public string $prompt;

    public Collection|array $attachments;

    public Lab|array|string|null $provider;

    public ?string $model;

    public ?Decisions $approvalDecisions;

    /**
     * Create a new queued agent prompt instance.
     */
    public function __construct(
        Agent $agent,
        Decisions|string $prompt,
        Collection|array $attachments,
        Lab|array|string|null $provider,
        ?string $model,
    ) {
        $this->agent = $agent;
        $this->prompt = is_string($prompt) ? $prompt : '';
        $this->attachments = $attachments;
        $this->provider = $provider;
        $this->model = $model;
        $this->approvalDecisions = $prompt instanceof Decisions ? $prompt : null;
    }

    /**
     * Determine if the prompt contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->prompt, $string);
    }

    /**
     * Determine whether the prompt continues a paused tool approval.
     */
    public function isApprovalContinuation(): bool
    {
        return $this->approvalDecisions !== null;
    }
}
