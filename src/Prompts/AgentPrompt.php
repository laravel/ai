<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;

class AgentPrompt extends Prompt
{
    public readonly Agent $agent;

    public readonly Collection $attachments;

    public readonly ?int $timeout;

    public readonly ?string $invocationId;

    public function __construct(
        Agent $agent,
        string $prompt,
        Collection|array $attachments,
        TextProvider $provider,
        string $model,
        ?int $timeout = null,
        ?string $invocationId = null,
        ?Decision $resume = null,
    ) {
        parent::__construct($prompt, $provider, $model, $resume);

        $this->agent = $agent;
        $this->attachments = Collection::make($attachments);
        $this->timeout = $timeout;
        $this->invocationId = $invocationId;
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
            $this->resume,
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
