<?php

namespace Laravel\Ai\Gateway\Ollama\Concerns;

use Illuminate\Support\Str;
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
     * Validate the Ollama response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'Ollama Error: %s',
                $data['error'] ?? 'Unknown Ollama error.',
            ));
        }
    }

    /**
     * Parse the Ollama response data into a single step response.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): StepResponse {
        $message = $data['message'] ?? [];
        $model = $data['model'] ?? '';

        $text = $message['content'] ?? '';
        $rawToolCalls = $message['tool_calls'] ?? [];

        $mappedToolCalls = array_map(function (array $toolCall): ToolCall {
            $id = $toolCall['id'] ?? (string) Str::uuid7();

            return new ToolCall(
                id: $id,
                name: $toolCall['function']['name'] ?? '',
                arguments: $this->parseToolArguments($toolCall['function']['arguments'] ?? []),
                resultId: $toolCall['id'] ?? null,
            );
        }, $rawToolCalls);

        return new StepResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: filled($mappedToolCalls) ? FinishReason::ToolCalls : $this->extractFinishReason($data),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
        );
    }

    /**
     * Parse tool call arguments, handling both array and JSON string formats.
     */
    protected function parseToolArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        return json_decode($arguments ?? '{}', true) ?? [];
    }

    /**
     * Extract usage data from the Ollama response.
     */
    protected function extractUsage(array $data): Usage
    {
        return new Usage(
            $data['prompt_eval_count'] ?? 0,
            $data['eval_count'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the Ollama response.
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        return match ($data['done_reason'] ?? '') {
            'stop' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length' => FinishReason::Length,
            default => FinishReason::Unknown,
        };
    }
}
