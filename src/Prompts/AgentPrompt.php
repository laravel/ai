<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;

class AgentPrompt extends Prompt
{
    public readonly Agent $agent;

    public readonly Collection $attachments;

    public readonly ?int $timeout;

    public readonly ?string $invocationId;

    public readonly ?string $parentInvocationId;

    public readonly ?string $parentToolInvocationId;

    // Internal failover bookkeeping, deliberately not part of the prompt's public surface...
    protected readonly bool $isFinalAttempt;

    /**
     * @param  bool  $isFinalAttempt  whether the caller has run out of providers to retry this prompt against, so a failoverable exception is terminal
     */
    public function __construct(
        Agent $agent,
        string $prompt,
        Collection|array $attachments,
        TextProvider $provider,
        string $model,
        ?int $timeout = null,
        ?string $invocationId = null,
        ?Decisions $approvalDecisions = null,
        ?string $parentInvocationId = null,
        ?string $parentToolInvocationId = null,
        bool $isFinalAttempt = true,
    ) {
        parent::__construct($prompt, $provider, $model, $approvalDecisions);

        $this->agent = $agent;
        $this->attachments = Collection::make($attachments);
        $this->timeout = $timeout;
        $this->invocationId = $invocationId;
        $this->parentInvocationId = $parentInvocationId;
        $this->parentToolInvocationId = $parentToolInvocationId;
        $this->isFinalAttempt = $isFinalAttempt;
    }

    /**
     * Determine whether the caller has run out of providers to retry this prompt against.
     *
     * @internal
     */
    public function isFinalAttempt(): bool
    {
        return $this->isFinalAttempt;
    }

    /**
     * Determine if the prompt contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->prompt, $string);
    }

    /**
     * Prepend to the prompt and return a new prompt instance.
     */
    public function prepend(string $prompt): AgentPrompt
    {
        return $this->revise($prompt.PHP_EOL.PHP_EOL.$this->prompt);
    }

    /**
     * Append to the prompt and return a new prompt instance.
     */
    public function append(string $prompt): AgentPrompt
    {
        return $this->revise($this->prompt.PHP_EOL.PHP_EOL.$prompt);
    }

    /**
     * Revise the prompt and return a new prompt instance.
     */
    public function revise(string $prompt, Collection|array|null $attachments = null): AgentPrompt
    {
        if ($this->hasApprovalDecisions()) {
            return $this;
        }

        if (is_array($attachments)) {
            $attachments = new Collection($attachments);
        }

        return new self(
            $this->agent,
            $prompt,
            $attachments ?? $this->attachments,
            $this->provider,
            $this->model,
            $this->timeout,
            $this->invocationId,
            $this->approvalDecisions,
            $this->parentInvocationId,
            $this->parentToolInvocationId,
            $this->isFinalAttempt,
        );
    }

    /**
     * Add new attachment to the prompt, returning a new prompt instance.
     */
    public function withAttachments(Collection|array $attachments): AgentPrompt
    {
        return $this->revise($this->prompt, $attachments);
    }

    /**
     * Get the provider instance.
     */
    public function provider(): TextProvider
    {
        return $this->provider;
    }
}
