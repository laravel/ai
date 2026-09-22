<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;

trait MapsMessages
{
    /**
     * Map the given Laravel messages to Gemini interaction input steps.
     */
    protected function mapMessagesToInput(array $messages): array
    {
        $input = [];

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $input),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $input),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $input),
            };
        }

        return $input;
    }

    /**
     * Map a user message to a Gemini user input step.
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$input): void
    {
        // Gemini rejects a text block without text, so an attachment-only message sends none...
        $content = filled($message->content) ? [['type' => 'text', 'text' => $message->content]] : [];

        if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments));
        }

        $input[] = [
            'type' => 'user_input',
            'content' => $content,
        ];
    }

    /**
     * Map an assistant message to the Gemini steps that produced it.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$input): void
    {
        // Gemini requires its own steps, thought steps included, replayed exactly as it returned them...
        if ($message instanceof AssistantMessage && filled($message->replayBlocks)) {
            foreach ($message->replayBlocks as $step) {
                // Gemini rejects the empty array PHP decodes an argument-less call's object into...
                $input[] = isset($step['arguments'])
                    ? [...$step, 'arguments' => (object) $step['arguments']]
                    : $step;
            }

            return;
        }

        if (filled($message->content)) {
            $input[] = [
                'type' => 'model_output',
                'content' => [['type' => 'text', 'text' => $message->content]],
            ];
        }

        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $toolCall) {
                $input[] = [
                    'type' => 'function_call',
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'arguments' => (object) $toolCall->arguments,
                ];
            }
        }
    }

    /**
     * Map a tool result message to Gemini function result steps.
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$input): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        foreach ($this->buildFunctionResultSteps($message->toolResults->all()) as $step) {
            $input[] = $step;
        }
    }
}
