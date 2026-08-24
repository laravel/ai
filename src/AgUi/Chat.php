<?php

namespace Laravel\Ai\AgUi;

use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\ChatInput;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Protocols\AgUiProtocol;

class Chat implements ChatInput
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
        $message = $this->trailingUserMessage();

        return $message === null ? null : AgUi::messageFrom($message);
    }

    /**
     * Get the tool approval decisions the input resumes an interrupted run with, if it contains any.
     */
    public function decisions(): ?Decisions
    {
        return AgUi::decisionsFrom($this->array('resume'));
    }

    /**
     * Get every turn before the one this input resolves to.
     *
     * @return list<Message>
     */
    public function history(): array
    {
        $messages = $this->messages();

        return AgUi::messagesFrom($this->trailingUserMessage() === null
            ? $messages
            : array_slice($messages, 0, -1));
    }

    /**
     * Get the stream protocol that reports the input's thread and run identity.
     */
    public function protocol(): AgUiProtocol
    {
        return new AgUiProtocol($this->threadId(), $this->runId());
    }

    /**
     * Get the ID of the thread the run belongs to.
     */
    public function threadId(): string
    {
        return (string) ($this->input['threadId'] ?? '');
    }

    /**
     * Get the ID of the run.
     */
    public function runId(): string
    {
        return (string) ($this->input['runId'] ?? '');
    }

    /**
     * Get the ID of the run this run branches from, if any.
     */
    public function parentRunId(): ?string
    {
        $parentRunId = $this->input['parentRunId'] ?? null;

        return is_string($parentRunId) && filled($parentRunId) ? $parentRunId : null;
    }

    /**
     * Get the AG-UI messages the input was created from.
     *
     * @return array<int, mixed>
     */
    public function messages(): array
    {
        return array_values($this->array('messages'));
    }

    /**
     * Get the shared state the client sent with the run.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->array('state');
    }

    /**
     * Get the client provided tools the run may call.
     *
     * @return array<int, mixed>
     */
    public function tools(): array
    {
        return array_values($this->array('tools'));
    }

    /**
     * Get the context the client sent with the run.
     *
     * @return array<int, mixed>
     */
    public function context(): array
    {
        return array_values($this->array('context'));
    }

    /**
     * Get the framework specific properties the client forwarded with the run.
     *
     * @return array<string, mixed>
     */
    public function forwardedProps(): array
    {
        return $this->array('forwardedProps');
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
}
