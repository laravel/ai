<?php

namespace Laravel\Ai\Gateway\Mistral\Concerns;

use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Providers\SupportsFileSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\ProviderTool;
use RuntimeException;

trait BuildsConversationRequests
{
    /**
     * Build the request body for the Conversations API.
     */
    protected function buildConversationRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $body = [
            'model' => $model,
            'inputs' => $this->mapMessagesToConversationInputs($messages),
            'tools' => $this->mapConversationTools($tools, $provider),
            'store' => false,
            'stream' => false,
        ];

        if (filled($instructions)) {
            $body['instructions'] = $instructions;
        }

        $completionArgs = Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
            'max_tokens' => $options?->maxTokens,
            'response_format' => filled($schema) ? $this->buildResponseFormat($schema) : null,
        ]);

        if (filled($completionArgs)) {
            $body['completion_args'] = $completionArgs;
        }

        $providerOptions = $options?->providerOptions($provider->driver());

        if (filled($providerOptions)) {
            $body = array_merge($body, $providerOptions);
        }

        return $body;
    }

    /**
     * Map the given tools to Conversations API tool definitions.
     */
    protected function mapConversationTools(array $tools, Provider $provider): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof FileSearch) {
                if (! $provider instanceof SupportsFileSearch) {
                    throw new RuntimeException('Provider ['.$provider->name().'] does not support file search.');
                }

                $mapped[] = [
                    'type' => 'document_library',
                    ...$provider->fileSearchToolOptions($tool),
                ];
            } elseif ($tool instanceof ProviderTool) {
                throw new RuntimeException('Mistral does not support ['.class_basename($tool).'] provider tools.');
            } elseif ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map the given Laravel messages to Conversations API input entries.
     */
    protected function mapMessagesToConversationInputs(array $messages): array
    {
        $inputs = [];

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserConversationInput($message, $inputs),
                MessageRole::Assistant => $this->mapAssistantConversationInput($message, $inputs),
                MessageRole::ToolResult => $this->mapToolResultConversationInput($message, $inputs),
            };
        }

        return $inputs;
    }

    /**
     * Map a user message to a Conversations API input entry.
     */
    protected function mapUserConversationInput(UserMessage|Message $message, array &$inputs): void
    {
        if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
            throw new RuntimeException('Mistral does not support attachments when using file search.');
        }

        $inputs[] = [
            'role' => 'user',
            'content' => $message->content,
        ];
    }

    /**
     * Map an assistant message to Conversations API input entries.
     */
    protected function mapAssistantConversationInput(AssistantMessage|Message $message, array &$inputs): void
    {
        if (filled($message->content)) {
            $inputs[] = [
                'role' => 'assistant',
                'content' => $message->content,
            ];
        }

        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $toolCall) {
                $inputs[] = [
                    'object' => 'entry',
                    'type' => 'function.call',
                    'tool_call_id' => $toolCall->resultId ?? $toolCall->id,
                    'name' => $toolCall->name,
                    'arguments' => json_encode($toolCall->arguments ?: (object) []),
                ];
            }
        }
    }

    /**
     * Map a tool result message to Conversations API input entries.
     */
    protected function mapToolResultConversationInput(ToolResultMessage|Message $message, array &$inputs): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        foreach ($message->toolResults as $toolResult) {
            $inputs[] = [
                'object' => 'entry',
                'type' => 'function.result',
                'tool_call_id' => $toolResult->resultId ?? $toolResult->id,
                'result' => $this->serializeToolResultOutput($toolResult->result),
            ];
        }
    }
}
