<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Tools\ProviderTool;

class AgentPrompt extends Prompt
{
    public readonly Agent $agent;

    public readonly Collection $attachments;

    /**
     * The ad-hoc message history to send ahead of the prompt.
     *
     * @var list<Message>|null
     */
    public readonly ?array $messages;

    /**
     * The tools available for this run, or null to use the tools the agent declares.
     *
     * @var array<int, Tool|ProviderTool|Agent>|null
     */
    public readonly ?array $tools;

    public readonly ?int $timeout;

    public readonly ?string $invocationId;

    public readonly ?string $parentInvocationId;

    public readonly ?string $parentToolInvocationId;

    protected readonly bool $isFinalAttempt;

    /**
     * @param  bool  $isFinalAttempt  Whether the caller has run out of providers to retry this prompt against.
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
        ?array $messages = null,
        ?array $tools = null,
    ) {
        parent::__construct($prompt, $provider, $model, $approvalDecisions);

        $this->agent = $agent;
        $this->attachments = Collection::make($attachments);
        $this->messages = $messages;
        $this->tools = $tools;
        $this->timeout = $timeout;
        $this->invocationId = $invocationId;
        $this->parentInvocationId = $parentInvocationId;
        $this->parentToolInvocationId = $parentToolInvocationId;
        $this->isFinalAttempt = $isFinalAttempt;
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
            $this->messages,
            $this->tools,
        );
    }

    /**
     * Replace the tools for this run, returning a new prompt instance.
     *
     * @param  iterable<int, Tool|ProviderTool|Agent>  $tools
     */
    public function withTools(iterable $tools): AgentPrompt
    {
        if ($this->hasApprovalDecisions()) {
            return $this;
        }

        return new self(
            $this->agent,
            $this->prompt,
            $this->attachments,
            $this->provider,
            $this->model,
            $this->timeout,
            $this->invocationId,
            $this->approvalDecisions,
            $this->parentInvocationId,
            $this->parentToolInvocationId,
            $this->isFinalAttempt,
            $this->messages,
            [...$tools],
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

    /**
     * Determine whether the caller has run out of providers to retry this prompt against.
     *
     * @internal
     */
    public function isFinalAttempt(): bool
    {
        return $this->isFinalAttempt;
    }
}
