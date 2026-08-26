<?php

namespace Laravel\Ai\AgentUserInteraction;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Protocols\AgUiProtocol;

class Chat implements AgentInput
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(protected array $input) {}

    /**
     * Get the newest user message, if the input contains one.
     */
    public function message(): ?UserMessage
    {
        if (array_key_exists('resume', $this->input)) {
            return null;
        }

        $message = $this->trailingUserMessage();

        return $message === null ? null : AgentUserInteraction::fromMessage($message);
    }

    /**
     * Get the tool approval decisions the input resumes an interrupted run with, if it contains any.
     */
    public function decisions(): ?Decisions
    {
        return AgentUserInteraction::decisionsFrom($this->array('resume'));
    }

    /**
     * Get every turn before the one this input resolves to.
     *
     * @return list<Message>
     */
    public function history(): array
    {
        $messages = $this->messages();

        return AgentUserInteraction::fromMessages($this->trailingUserMessage() === null
            ? $messages
            : array_slice($messages, 0, -1));
    }

    /**
     * Get the stream protocol that reports the input's thread and run identity.
     */
    public function protocol(): AgUiProtocol
    {
        return new AgUiProtocol(
            $this->threadId() ?: null,
            $this->runId() ?: null,
        );
    }

    /**
     * Get the ID of the thread the run belongs to.
     */
    public function threadId(): string
    {
        return $this->string('threadId');
    }

    /**
     * Get the ID of the run.
     */
    public function runId(): string
    {
        return $this->string('runId');
    }

    /**
     * Get the AG-UI messages the input was created from.
     *
     * @return array<int, mixed>
     */
    protected function messages(): array
    {
        return array_values($this->array('messages'));
    }

    /**
     * Get the newest message when it is a user message.
     *
     * @return array<string, mixed>|null
     */
    protected function trailingUserMessage(): ?array
    {
        $messages = $this->messages();

        $message = $messages === [] ? null : $messages[array_key_last($messages)];

        return is_array($message) && ($message['role'] ?? null) === 'user' ? $message : null;
    }

    /**
     * Get the given input key as an array.
     *
     * @return array<mixed>
     */
    protected function array(string $key): array
    {
        $value = $this->input[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * Get the given input key as a string.
     */
    protected function string(string $key): string
    {
        $value = $this->input[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
