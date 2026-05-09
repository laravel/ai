<?php

namespace Laravel\Ai\Gateway\DeepSeek\Concerns;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\SingleTurnResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

trait ParsesTextResponses
{
    /**
     * Validate the DeepSeek response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'DeepSeek Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown DeepSeek error.',
            ));
        }
    }

    /**
     * Parse a single DeepSeek response into a SingleTurnResponse.
     *
     * DeepSeek thinking-mode responses can include `reasoning_content` on each
     * choice's message. We capture it into `providerContentBlocks`; the message
     * mapper only replays it for assistant messages that include tool calls.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): SingleTurnResponse {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $model = $data['model'] ?? '';

        $text = $message['content'] ?? '';
        $rawToolCalls = $message['tool_calls'] ?? [];
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($choice);

        $mappedToolCalls = array_map(fn (array $toolCall) => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['function']['name'] ?? '',
            json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), $rawToolCalls);

        $providerContentBlocks = [];

        if (filled($message['reasoning_content'] ?? null)) {
            $providerContentBlocks['reasoning_content'] = $message['reasoning_content'];
        }

        return new SingleTurnResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model),
            structured: $structured ? (json_decode($text, true) ?? []) : null,
            responseId: null,
            providerContentBlocks: $providerContentBlocks,
        );
    }

    /**
     * Extract usage data from the response.
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];
        $details = $usage['completion_tokens_details'] ?? [];

        return new Usage(
            promptTokens: $usage['prompt_tokens'] ?? 0,
            completionTokens: $usage['completion_tokens'] ?? 0,
            cacheReadInputTokens: $usage['prompt_cache_hit_tokens'] ?? 0,
            reasoningTokens: $details['reasoning_tokens'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the response.
     */
    protected function extractFinishReason(array $choice): FinishReason
    {
        return match ($choice['finish_reason'] ?? '') {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }
}
