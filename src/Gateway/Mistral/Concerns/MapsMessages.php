<?php

namespace Laravel\Ai\Gateway\Mistral\Concerns;

use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionMessages;
use Laravel\Ai\Messages\AssistantMessage;

trait MapsMessages
{
    use MapsChatCompletionMessages;

    /**
     * Mistral requires the full assistant content, thinking chunks included, to be replayed.
     *
     * @return array<string, mixed>
     */
    protected function assistantReplayFields(AssistantMessage $message): array
    {
        $content = $message->providerContentBlocks['content'] ?? [];

        return $content ? ['content' => $content] : [];
    }
}
