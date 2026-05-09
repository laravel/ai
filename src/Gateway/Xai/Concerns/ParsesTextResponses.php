<?php

namespace Laravel\Ai\Gateway\Xai\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\SingleTurnResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;

trait ParsesTextResponses
{
    /**
     * Validate the xAI response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'xAI Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown xAI error.',
            ));
        }

        if (($data['status'] ?? '') === 'failed') {
            $error = $data['error'] ?? [];

            throw new AiException(sprintf(
                'xAI Error: [%s] %s',
                $error['code'] ?? 'unknown',
                $error['message'] ?? 'The response failed without an error message.',
            ));
        }
    }

    /**
     * Parse a single xAI response into a SingleTurnResponse.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): SingleTurnResponse {
        $output = $data['output'] ?? [];
        $model = $data['model'] ?? '';

        $text = $this->extractText($output);
        $citations = $this->extractCitations($output);
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($data);

        $mappedToolCalls = $this->mapToolCallsWithReasoning($output);

        return new SingleTurnResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model, $citations),
            structured: $structured ? (json_decode($text, true) ?? []) : null,
            responseId: $data['id'] ?? null,
        );
    }

    /**
     * Extract the text content from the output array.
     */
    protected function extractText(array $output): string
    {
        $lastOutput = last($output);

        if (is_array($lastOutput)) {
            return $lastOutput['content'][0]['text'] ?? '';
        }

        return '';
    }

    /**
     * Extract citations from the output array.
     */
    protected function extractCitations(array $output): Collection
    {
        $citations = new Collection;

        foreach ($output as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                foreach ($content['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? '') === 'url_citation') {
                        $citations->push(new UrlCitation(
                            $annotation['url'] ?? '',
                            $annotation['title'] ?? null,
                            isset($annotation['start_index']) ? (int) $annotation['start_index'] : null,
                            isset($annotation['end_index']) ? (int) $annotation['end_index'] : null,
                        ));
                    }
                }
            }
        }

        return $citations->values();
    }

    /**
     * Extract usage data from the response.
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? 0;
        $cachedTokens = $usage['input_tokens_details']['cached_tokens'] ?? 0;

        return new Usage(
            $inputTokens - $cachedTokens,
            $usage['output_tokens'] ?? 0,
            0,
            $cachedTokens,
            $usage['output_tokens_details']['reasoning_tokens'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the xAI response.
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        $lastOutput = last($data['output'] ?? []);
        $status = $lastOutput['status'] ?? $data['status'] ?? '';
        $type = $lastOutput['type'] ?? '';

        return match ($status) {
            'incomplete' => FinishReason::Length,
            'failed' => FinishReason::Error,
            'completed' => match ($type) {
                'function_call' => FinishReason::ToolCalls,
                'message' => FinishReason::Stop,
                default => str_ends_with((string) $type, '_call') ? FinishReason::ToolCalls : FinishReason::Unknown,
            },
            default => FinishReason::Unknown,
        };
    }

    /**
     * Map tool calls with their associated reasoning blocks.
     *
     * @return array<ToolCall>
     */
    protected function mapToolCallsWithReasoning(array $output): array
    {
        $toolCalls = [];
        $latestReasoning = null;

        foreach ($output as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'reasoning') {
                $latestReasoning = $item;

                continue;
            }

            if ($type === 'function_call') {
                $toolCalls[] = new ToolCall(
                    $item['id'] ?? '',
                    $item['name'] ?? '',
                    json_decode($item['arguments'] ?? '{}', true) ?? [],
                    $item['call_id'] ?? null,
                    $latestReasoning ? ($latestReasoning['id'] ?? null) : null,
                    $latestReasoning ? ($latestReasoning['summary'] ?? null) : null,
                );
            }
        }

        return $toolCalls;
    }
}
