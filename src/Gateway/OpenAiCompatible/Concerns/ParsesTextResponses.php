<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible\Concerns;

use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

trait ParsesTextResponses
{
    use DecodesStructuredOutput;

    /**
     * Validate the OpenAI-compatible response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'OpenAI-compatible Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown error.',
            ));
        }
    }

    /**
     * Parse a single OpenAI-compatible response into a StepResponse.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): StepResponse {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $model = $data['model'] ?? '';

        $text = $message['content'] ?? '';
        $rawToolCalls = $message['tool_calls'] ?? [];

        $mappedToolCalls = array_map(fn (array $toolCall): ToolCall => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['function']['name'] ?? '',
            json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), $rawToolCalls);

        return new StepResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: $this->extractFinishReason($choice),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
        );
    }

    /**
     * Extract usage data from the response.
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];
        $promptDetails = $usage['prompt_tokens_details'] ?? [];
        $completionDetails = $usage['completion_tokens_details'] ?? [];

        return new Usage(
            promptTokens: $usage['prompt_tokens'] ?? 0,
            completionTokens: $usage['completion_tokens'] ?? 0,
            cacheReadInputTokens: $promptDetails['cached_tokens'] ?? 0,
            reasoningTokens: $completionDetails['reasoning_tokens'] ?? 0,
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
