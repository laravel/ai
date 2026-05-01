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
     * Map the given Laravel messages to Gemini contents format.
     */
    protected function mapMessagesToContents(array $messages): array
    {
        $contents = [];

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $contents),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $contents),
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $contents),
            };
        }

        return $contents;
    }

    /**
     * Map a user message to Gemini format.
     */
    protected function mapUserMessage(UserMessage|Message $message, array &$contents): void
    {
        $parts = [['text' => $message->content]];

        if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
            $parts = array_merge($parts, $this->mapAttachments($message->attachments));
        }

        $contents[] = [
            'role' => 'user',
            'parts' => $parts,
        ];
    }

    /**
     * Map an assistant message to Gemini format.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$contents): void
    {
        $parts = [];

        if (filled($message->content)) {
            $parts[] = ['text' => $message->content];
        }

        if ($message instanceof AssistantMessage && $message->toolCalls->isNotEmpty()) {
            foreach ($message->toolCalls as $toolCall) {
                $functionCall = ['name' => $toolCall->name];

                if (filled($toolCall->arguments)) {
                    $functionCall['args'] = $toolCall->arguments;
                }

                $parts[] = ['functionCall' => $functionCall];
            }
        }

        if (filled($parts)) {
            $contents[] = [
                'role' => 'model',
                'parts' => $parts,
            ];
        }
    }

    /**
     * Map a tool result message to Gemini format.
     */
    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$contents): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        $parts = $this->buildFunctionResponseParts($message->toolResults->all());

        if (filled($parts)) {
            $contents[] = [
                'role' => 'user',
                'parts' => $parts,
            ];
        }
    }

    /**
     * Serialize a tool result output value to a string.
     */
    protected function serializeToolResultOutput(mixed $output): string
    {
        if (is_string($output)) {
            return $output;
        }

        return is_array($output) ? json_encode($output) : strval($output);
    }
}
