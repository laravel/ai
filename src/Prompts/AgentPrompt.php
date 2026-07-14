<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;

class AgentPrompt extends Prompt
{
    public readonly Agent $agent;

    public readonly Collection $attachments;

    public readonly ?int $timeout;

    public readonly ?string $invocationId;

    /**
     * @param  Message[]|string  $prompt
     */
    public function __construct(
        Agent $agent,
        array|string $prompt,
        Collection|array $attachments,
        TextProvider $provider,
        string $model,
        ?int $timeout = null,
        ?string $invocationId = null,
    ) {
        parent::__construct($prompt, $provider, $model);

        $this->agent = $agent;
        $this->attachments = Collection::make($attachments);
        $this->timeout = $timeout;
        $this->invocationId = $invocationId;
    }

    /**
     * Determine if the prompt contains the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->text(), $string);
    }

    /**
     * Prepend to the prompt and return a new prompt instance.
     */
    public function prepend(string $prompt): AgentPrompt
    {
        return $this->revise($prompt.PHP_EOL.PHP_EOL.$this->text());
    }

    /**
     * Append to the prompt and return a new prompt instance.
     */
    public function append(string $prompt): AgentPrompt
    {
        return $this->revise($this->text().PHP_EOL.PHP_EOL.$prompt);
    }

    /**
     * Revise the trailing prompt message and return a new prompt instance.
     */
    public function revise(string $prompt, Collection|array|null $attachments = null): AgentPrompt
    {
        if (is_array($attachments)) {
            $attachments = new Collection($attachments);
        }

        $trailing = $this->trailingMessage();

        $revised = $this->hasTranscript()
            ? [...$this->history(), new UserMessage($prompt, $trailing instanceof UserMessage ? $trailing->attachments : [])]
            : $prompt;

        return new self(
            $this->agent,
            $revised,
            $attachments ?? $this->attachments,
            $this->provider,
            $this->model,
            $this->timeout,
            $this->invocationId,
        );
    }

    /**
     * Add new attachment to the prompt, returning a new prompt instance.
     */
    public function withAttachments(Collection|array $attachments): AgentPrompt
    {
        return $this->revise($this->text(), $attachments);
    }

    /**
     * Get the provider instance.
     */
    public function provider(): TextProvider
    {
        return $this->provider;
    }
}
