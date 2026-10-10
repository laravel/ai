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
     * Map an assistant message, replaying its reasoning details with its tool calls.
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
