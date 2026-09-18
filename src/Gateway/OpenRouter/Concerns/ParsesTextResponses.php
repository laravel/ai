<?php

namespace Laravel\Ai\Gateway\OpenRouter\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Concerns\JoinsReasoning;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;

trait ParsesTextResponses
{
    use DecodesStructuredOutput, JoinsReasoning;

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
                $data['error']['type'] ?? $data['error']['code'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown OpenRouter error.',
            ));
        }
    }

    /**
     * Parse the OpenRouter response data into a single step response.
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
        $citations = $this->extractCitations($message);

        $toolCalls = array_map(fn (array $toolCall): ToolCall => new ToolCall(
            $toolCall['id'] ?? '',
            $toolCall['function']['name'] ?? '',
            json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
            $toolCall['id'] ?? null,
        ), $message['tool_calls'] ?? []);

        return new StepResponse(
            text: $text,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($choice),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model, $citations),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            reasoning: $this->extractReasoning($message),
        );
    }

    /**
     * Extract the reasoning text from an assistant message.
     */
    protected function extractReasoning(array $message): string
    {
        if (filled($message['reasoning'] ?? '')) {
            return (string) $message['reasoning'];
        }

        return static::joinReasoning(
            (new Collection($message['reasoning_details'] ?? []))
                ->map(fn (array $detail): string => (string) ($detail['text'] ?? $detail['summary'] ?? ''))
        );
    }

    /**
     * Extract URL citations from the message annotations array.
     */
    protected function extractCitations(array $message): Collection
    {
        $citations = new Collection;

        foreach ($message['annotations'] ?? [] as $annotation) {
            if (($annotation['type'] ?? '') === 'url_citation') {
                $urlCitation = $annotation['url_citation'] ?? [];

                $citations->push(new UrlCitation(
                    $urlCitation['url'] ?? '',
                    $urlCitation['title'] ?? null,
                    isset($urlCitation['start_index']) ? (int) $urlCitation['start_index'] : null,
                    isset($urlCitation['end_index']) ? (int) $urlCitation['end_index'] : null,
                ));
            }
        }

        return $citations->values();
    }

    /**
     * Extract usage data from the response.
     */
    protected function extractUsage(array $data): TextUsage
    {
        $usage = $data['usage'] ?? [];

        return new TextUsage(
            inputTokens: $usage['prompt_tokens'] ?? 0,
            outputTokens: $usage['completion_tokens'] ?? 0,
            cacheReadInputTokens: $usage['prompt_tokens_details']['cached_tokens'] ?? null,
            cacheWriteInputTokens: $usage['prompt_tokens_details']['cache_write_tokens'] ?? null,
            reasoningTokens: $usage['completion_tokens_details']['reasoning_tokens'] ?? null,
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
