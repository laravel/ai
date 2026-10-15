<?php

namespace Laravel\Ai\Gateway\Mistral\Concerns;

use Illuminate\Support\Collection;
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
     * Validate the Mistral response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error']) || ($data['object'] ?? null) === 'error') {
            throw new AiException(sprintf(
                'Mistral Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown Mistral error.',
            ));
        }
    }

    /**
     * Parse the Mistral response data into a single step response.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): StepResponse {
        $choice = $data['choices'][0] ?? [];
        $model = $data['model'] ?? '';

        $messages = new Collection($choice['messages'] ?? [$choice['message'] ?? []]);
        $assistantMessages = $messages->where('role', 'assistant');
        $serverToolCallIds = $messages->where('role', 'tool')->pluck('tool_call_id')->all();

        $text = $assistantMessages->map(fn (array $message): string => $this->extractContentText($message['content'] ?? ''))->implode('');

        $toolCalls = $assistantMessages
            ->flatMap(fn (array $message): array => $message['tool_calls'] ?? [])
            ->reject(fn (array $toolCall): bool => in_array($toolCall['id'] ?? null, $serverToolCallIds, true))
            ->map(fn (array $toolCall): ToolCall => new ToolCall(
                $toolCall['id'] ?? '',
                $toolCall['function']['name'] ?? '',
                json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [],
                $toolCall['id'] ?? null,
            ))
            ->values()
            ->all();

        return new StepResponse(
            text: $text,
            toolCalls: $toolCalls,
            finishReason: $this->extractFinishReason($choice),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
        );
    }

    /**
     * Extract the text from a message content value, which may be a list of content chunks.
     */
    protected function extractContentText(mixed $content): string
    {
        if (! is_array($content)) {
            return (string) $content;
        }

        return implode('', array_map(
            fn (mixed $chunk): string => is_array($chunk) ? (string) ($chunk['text'] ?? '') : (string) $chunk,
            $content,
        ));
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
            'length', 'model_length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            'error' => FinishReason::Error,
            default => FinishReason::Unknown,
        };
    }
}
