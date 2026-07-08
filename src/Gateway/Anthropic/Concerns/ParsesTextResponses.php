<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;

trait ParsesTextResponses
{
    use DecodesStructuredOutput;

    /**
     * Validate the Anthropic response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || ($data['type'] ?? '') === 'error') {
            throw new AiException(sprintf(
                'Anthropic Error: [%s] %s',
                $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown Anthropic error.',
            ));
        }
    }

    /**
     * Parse the Anthropic response data into a single step response.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        bool $structured,
    ): StepResponse {
        return $this->buildStepResponse(
            $data['content'] ?? [],
            $provider,
            $data['model'] ?? '',
            $this->extractUsage($data),
            $this->extractFinishReason($data),
            $structured,
        );
    }

    /**
     * Build a single step response from Anthropic content blocks.
     */
    protected function buildStepResponse(
        array $content,
        Provider $provider,
        string $model,
        Usage $usage,
        FinishReason $finishReason,
        bool $structured,
    ): StepResponse {
        $text = $this->extractText($content);
        $toolCalls = $this->extractToolCalls($content);
        $citations = $this->extractCitations($content);

        $realToolCalls = array_values(array_filter($toolCalls, fn (ToolCall $tc) => $tc->name !== 'output_structured_data'));
        $hasStructuredToolCall = count($realToolCalls) < count($toolCalls);

        $structuredData = null;

        if ($structured || $hasStructuredToolCall) {
            $structuredData = $this->extractStructuredOutput($content);

            if (empty($structuredData) && filled($text)) {
                $structuredData = $this->decodeStructuredOutput($text);
            }
        }

        // If the only tool call was the synthetic structured output, this is really a stop.
        if ($finishReason === FinishReason::ToolCalls && empty($realToolCalls)) {
            $finishReason = FinishReason::Stop;
        }

        return new StepResponse(
            text: $hasStructuredToolCall && ! empty($structuredData) ? (json_encode($structuredData) ?: '') : $text,
            toolCalls: $realToolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model, $citations),
            structured: $structuredData,
            providerContentBlocks: $content,
        );
    }

    /**
     * Extract the text content from Anthropic content blocks.
     */
    protected function extractText(array $content): string
    {
        $textBlocks = array_filter($content, fn (array $block) => ($block['type'] ?? '') === 'text');

        return implode('', array_column($textBlocks, 'text'));
    }

    /**
     * Extract tool calls from Anthropic content blocks.
     *
     * @return array<ToolCall>
     */
    protected function extractToolCalls(array $content): array
    {
        $toolUseBlocks = array_filter($content, fn (array $block) => ($block['type'] ?? '') === 'tool_use');

        return array_values(array_map(fn (array $block) => new ToolCall(
            $block['id'] ?? '',
            $block['name'] ?? '',
            $block['input'] ?? [],
            $block['id'] ?? null,
        ), $toolUseBlocks));
    }

    /**
     * Extract citations from Anthropic content blocks.
     */
    protected function extractCitations(array $content): Collection
    {
        $citations = new Collection;

        foreach ($content as $block) {
            $blockType = $block['type'] ?? '';

            if ($blockType === 'web_search_tool_result') {
                foreach ($block['search_results'] ?? [] as $result) {
                    $citations->push(new UrlCitation(
                        $result['url'] ?? '',
                        $result['title'] ?? null,
                    ));
                }
            }

            if ($blockType === 'text') {
                foreach ($block['citations'] ?? [] as $citation) {
                    if (($citation['type'] ?? '') === 'web_search_result_location') {
                        $citations->push(new UrlCitation(
                            $citation['url'] ?? '',
                            $citation['title'] ?? null,
                        ));
                    }
                }
            }
        }

        return $citations->unique('url')->values();
    }

    /**
     * Extract usage data from the Anthropic response.
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];

        return new Usage(
            $usage['input_tokens'] ?? 0,
            $usage['output_tokens'] ?? 0,
            $usage['cache_creation_input_tokens'] ?? 0,
            $usage['cache_read_input_tokens'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the Anthropic response.
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        return match ($data['stop_reason'] ?? '') {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'pause_turn' => FinishReason::Continue,
            'max_tokens' => FinishReason::Length,
            default => FinishReason::Unknown,
        };
    }

    /**
     * Extract structured output from the synthetic tool call.
     */
    protected function extractStructuredOutput(array $content): array
    {
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'output_structured_data') {
                return $block['input'] ?? [];
            }
        }

        return [];
    }
}
