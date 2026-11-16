<?php

namespace Laravel\Ai\Gateway\DeepSeek\Concerns;

use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionMessages;
use Laravel\Ai\Messages\AssistantMessage;

trait MapsMessages
{
    use MapsChatCompletionMessages {
        mapReasoningReplay as mapChatCompletionReasoningReplay;
    }

    /**
     * Map the reasoning of a tool call turn, which DeepSeek's thinking mode requires even when empty.
     */
    protected function mapReasoningReplay(AssistantMessage $message): array
    {
        return $this->mapChatCompletionReasoningReplay($message) ?: ['reasoning_content' => ''];
    }
}
