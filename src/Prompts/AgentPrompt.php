<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\InvocationContext;

class AgentPrompt extends Prompt
{
    public readonly Agent $agent;

    public readonly Collection $attachments;

    public readonly ?int $timeout;

    public readonly ?string $invocationId;

    public readonly ?string $parentInvocationId;

    public readonly ?string $rootInvocationId;

    public function __construct(
        Agent $agent,
        string $prompt,
        Collection|array $attachments,
        TextProvider $provider,
        string $model,
        ?int $timeout = null,
        ?string $invocationId = null,
        ?string $parentInvocationId = null,
        ?string $rootInvocationId = null,
    ) {
        parent::__construct($prompt, $provider, $model);

        $this->agent = $agent;
        $this->attachments = Collection::make($attachments);
        $this->timeout = $timeout;
        $this->invocationId = $invocationId;
        $this->parentInvocationId = $parentInvocationId;
        $this->rootInvocationId = $rootInvocationId;
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
            $this->parentInvocationId,
            $this->rootInvocationId,
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
     * Build an invocation context from the ids carried on the prompt, if any.
     */
    public function invocationContext(): ?InvocationContext
    {
        return $this->invocationId === null
            ? null
            : new InvocationContext($this->invocationId, $this->parentInvocationId, $this->rootInvocationId);
    }

    /**
     * Set the invocation context on the prompt, returning a new prompt instance.
     */
    public function withInvocationContext(InvocationContext $context): AgentPrompt
    {
        return new self(
            $this->agent,
            $this->prompt,
            $this->attachments,
            $this->provider,
            $this->model,
            $this->timeout,
            $context->id,
            $context->parentId,
            $context->rootId,
        );
    }

    /**
     * Get the provider instance.
     */
    public function provider(): TextProvider
    {
        return $this->provider;
    }
}
