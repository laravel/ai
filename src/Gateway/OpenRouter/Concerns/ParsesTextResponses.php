<?php

namespace Laravel\Ai\Gateway\OpenRouter\Concerns;

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
     * Validate the OpenRouter response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'OpenRouter Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown OpenRouter error.',
            ));
        }
    }

    /**
     * Parse a single OpenRouter response into a SingleTurnResponse.
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

        return new SingleTurnResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model),
            structured: $structured ? (json_decode($text, true) ?? []) : null,
        );
    }

    /**
     * Extract usage data from the response.
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];

        return new Usage(
            $usage['prompt_tokens'] ?? 0,
            $usage['completion_tokens'] ?? 0,
            cacheWriteInputTokens: $usage['prompt_tokens_details']['cache_write_tokens'] ?? 0,
            cacheReadInputTokens: $usage['prompt_tokens_details']['cached_tokens'] ?? 0,
            reasoningTokens: $usage['completion_tokens_details']['reasoning_tokens'] ?? 0,
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
