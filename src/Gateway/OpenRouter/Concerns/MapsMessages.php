<?php

namespace Laravel\Ai\Gateway\OpenRouter\Concerns;

use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionMessages;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;

trait MapsMessages
{
    use MapsChatCompletionMessages {
        mapAssistantMessage as mapChatCompletionAssistantMessage;
    }

    /**
     * Map an assistant message to Chat Completions format.
     *
     * The reasoning details that produced a step's tool calls are sent back
     * unmodified, so the model continues the tool loop with its own reasoning
     * instead of starting over. OpenAI and Anthropic models carry encrypted or
     * signed blocks there that the upstream expects back as they were sent.
     */
    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$chatMessages): void
    {
        $this->mapChatCompletionAssistantMessage($message, $chatMessages);

        if ($message instanceof AssistantMessage
            && $message->toolCalls->isNotEmpty()
            && filled($message->replayBlocks)) {
            $chatMessages[array_key_last($chatMessages)]['reasoning_details'] = $message->replayBlocks;
        }
    }
}
