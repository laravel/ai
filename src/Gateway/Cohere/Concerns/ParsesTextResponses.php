<?php

namespace Laravel\Ai\Gateway\Cohere\Concerns;

use Laravel\Ai\Concerns\JoinsReasoning;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;

trait ParsesTextResponses
{
    use DecodesStructuredOutput, JoinsReasoning;

    /**
     * Validate the Cohere response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(mixed $data): void
    {
        if (! is_array($data) || ! is_array($data['message'] ?? null)) {
            throw new AiException(sprintf(
                'Cohere Error: %s',
                is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : 'Unknown Cohere error.',
            ));
        }
    }

    /**
     * Parse the Cohere response data into a single step response.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        string $model,
        bool $structured,
    ): StepResponse {
        $message = $data['message'];
        $content = is_array($message['content'] ?? null) ? $message['content'] : [];

        $text = implode('', array_map(
            fn (array $block): string => (string) ($block['text'] ?? ''),
            array_filter($content, fn (mixed $block): bool => is_array($block) && ($block['type'] ?? '') === 'text'),
        ));

        $reasoning = static::joinReasoning(array_map(
            fn (array $block): string => (string) ($block['thinking'] ?? ''),
            array_filter($content, fn (mixed $block): bool => is_array($block) && ($block['type'] ?? '') === 'thinking'),
        ));

        $toolCalls = array_map(fn (array $toolCall): ToolCall => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['function']['name'] ?? '',
            json_decode(($toolCall['function']['arguments'] ?? '') ?: '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), $message['tool_calls'] ?? []);

        return new StepResponse(
            text: $text,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($data['finish_reason'] ?? null),
            usage: $this->extractUsage($data['usage'] ?? []),
            meta: new Meta($provider->name(), $model),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            reasoning: $reasoning,
        );
    }

    /**
     * Extract usage data from a Cohere usage object.
     */
    protected function extractUsage(array $usage): TextUsage
    {
        return new TextUsage(
            inputTokens: (int) ($usage['tokens']['input_tokens'] ?? $usage['billed_units']['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['tokens']['output_tokens'] ?? $usage['billed_units']['output_tokens'] ?? 0),
            cacheReadInputTokens: isset($usage['cached_tokens']) ? (int) $usage['cached_tokens'] : null,
        );
    }

    /**
     * Map a Cohere finish reason to the finish reason enum.
     */
    protected function extractFinishReason(?string $finishReason): FinishReason
    {
        return match ($finishReason) {
            'COMPLETE', 'STOP_SEQUENCE' => FinishReason::Stop,
            'TOOL_CALL' => FinishReason::ToolCalls,
            'MAX_TOKENS' => FinishReason::Length,
            'ERROR', 'TIMEOUT' => FinishReason::Error,
            default => FinishReason::Unknown,
        };
    }
}
