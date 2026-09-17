<?php

namespace Laravel\Ai\Gateway\OpenRouter\Concerns;

use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionMessages;
use Laravel\Ai\Messages\AssistantMessage;

trait MapsMessages
{
    use MapsChatCompletionMessages;

    /**
     * @return array<string, mixed>
     */
    protected function assistantReplayFields(AssistantMessage $message): array
    {
        $details = $message->providerContentBlocks['reasoning_details'] ?? [];

        return $details ? ['reasoning_details' => $details] : [];
    }
}
