<?php

namespace Laravel\Ai\Gateway\Mistral\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

trait ParsesConversationResponses
{
    /**
     * Validate the Mistral conversation response data.
     *
     * @throws AiException
     */
    protected function validateConversationResponse(array $data): void
    {
        if (! $data || isset($data['error']) || ($data['object'] ?? null) === 'error') {
            throw new AiException(sprintf(
                'Mistral Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? ($data['message'] ?? 'Unknown Mistral error.'),
            ));
        }
    }

    /**
     * Parse the Mistral conversation response data into a single step response.
     */
    protected function parseConversationResponse(
        array $data,
        Provider $provider,
        string $model,
        bool $structured,
    ): StepResponse {
        $text = '';
        $toolCalls = [];
        $responseModel = $model;

        foreach ($data['outputs'] ?? [] as $output) {
            $responseModel = $output['model'] ?? $responseModel;

            if (($output['type'] ?? null) === 'message.output') {
                $text .= $this->extractConversationText($output['content'] ?? '');
            } elseif (($output['type'] ?? null) === 'function.call') {
                $toolCalls[] = new ToolCall(
                    $output['tool_call_id'] ?? '',
                    $output['name'] ?? '',
                    json_decode($output['arguments'] ?? '{}', true) ?? [],
                    $output['tool_call_id'] ?? null,
                );
            }
        }

        $usage = $data['usage'] ?? [];

        return new StepResponse(
            text: $text,
            toolCalls: $toolCalls,
            finishReason: filled($toolCalls) ? FinishReason::ToolCalls : FinishReason::Stop,
            usage: new Usage($usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0),
            meta: new Meta($provider->name(), $responseModel),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
        );
    }

    /**
     * Extract the text from a message output entry's content.
     */
    protected function extractConversationText(string|array $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        $chunks = array_is_list($content) ? $content : [$content];

        return (new Collection($chunks))
            ->filter(fn ($chunk) => ($chunk['type'] ?? null) === 'text')
            ->map(fn ($chunk) => $chunk['text'] ?? '')
            ->implode('');
    }
}
