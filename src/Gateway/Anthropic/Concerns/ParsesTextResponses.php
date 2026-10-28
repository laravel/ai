<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Concerns\JoinsReasoning;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;

trait ParsesTextResponses
{
    use DecodesStructuredOutput, JoinsReasoning;

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
        bool $strict = false,
    ): StepResponse {
        return $this->buildStepResponse(
            $data['content'] ?? [],
            $provider,
            $data['model'] ?? '',
            $this->extractUsage($data),
            $this->extractFinishReason($data),
            $structured,
            $strict,
        );
    }

    /**
     * Build a single step response from Anthropic content blocks.
     */
    protected function buildStepResponse(
        array $content,
        Provider $provider,
        string $model,
        TextUsage $usage,
        FinishReason $finishReason,
        bool $structured,
        bool $strict = false,
    ): StepResponse {
        $text = $this->extractText($content);
        $toolCalls = $this->extractToolCalls($content);
        $citations = $this->extractCitations($content);

        $realToolCalls = array_values(array_filter($toolCalls, fn (ToolCall $tc): bool => $tc->name !== 'output_structured_data'));
        $hasStructuredToolCall = count($realToolCalls) < count($toolCalls);

        // If the only tool call was the synthetic structured output, this is really a stop.
        if ($finishReason === FinishReason::ToolCalls && $realToolCalls === []) {
            $finishReason = FinishReason::Stop;
        }

        $structuredData = null;

        if ($structured || $hasStructuredToolCall) {
            $structuredData = $this->extractStructuredOutput($content);

            if (empty($structuredData) && filled($text)) {
                $structuredData = $this->decodeStructuredOutput($text, $finishReason, $strict);
            }
        }

        return new StepResponse(
            text: $hasStructuredToolCall && ! empty($structuredData) ? (json_encode($structuredData) ?: '') : $text,
            toolCalls: $realToolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model, $citations),
            structured: $structuredData,
            replayBlocks: $content,
            reasoning: $this->extractReasoning($content),
            providerToolCalls: $this->extractProviderToolCalls($content),
        );
    }

    /**
     * Extract the text content from Anthropic content blocks.
     */
    protected function extractText(array $content): string
    {
        $text = '';
        $afterTool = false;

        foreach ($content as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $blockText = (string) ($block['text'] ?? '');
                $text .= ($afterTool && $text !== '' ? $this->textSeparator($text, $blockText) : '').$blockText;
                $afterTool = false;
            } elseif ($this->isToolBlock($type)) {
                $afterTool = true;
            }
        }

        return $text;
    }

    /**
     * Get the separator between text written before a tool block and text written after it.
     */
    protected function textSeparator(string $before, string $after): string
    {
        return str_ends_with($before, "\n") || str_starts_with($after, "\n") ? '' : "\n\n";
    }

    /**
     * Determine if the given block type is a server tool call or its result.
     */
    protected function isToolBlock(string $type): bool
    {
        return $type === 'server_tool_use' || $this->isProviderToolResultBlock($type);
    }

    /**
     * Determine if the given block type is a provider tool result.
     */
    protected function isProviderToolResultBlock(string $type): bool
    {
        return str_ends_with($type, '_tool_result');
    }

    /**
     * Extract the reasoning text from Anthropic content blocks.
     */
    protected function extractReasoning(array $content): string
    {
        $thinkingBlocks = array_filter($content, fn (array $block): bool => ($block['type'] ?? '') === 'thinking');

        return static::joinReasoning(array_map(fn (array $block): string => $block['thinking'] ?? '', $thinkingBlocks));
    }

    /**
     * Extract the server tool use and result blocks, each keyed by the tool use it belongs to.
     *
     * @return array<int, ProviderToolCall>
     */
    protected function extractProviderToolCalls(array $content): array
    {
        return array_values(array_map(
            fn (array $block): ProviderToolCall => new ProviderToolCall($block['tool_use_id'] ?? $block['id'] ?? '', $block['type'], $block),
            array_filter($content, fn (array $block): bool => ($block['type'] ?? '') === 'server_tool_use' || $this->isProviderToolResultBlock((string) ($block['type'] ?? ''))),
        ));
    }

    /**
     * Extract tool calls from Anthropic content blocks.
     *
     * @return array<ToolCall>
     */
    protected function extractToolCalls(array $content): array
    {
        $toolUseBlocks = array_filter($content, fn (array $block): bool => ($block['type'] ?? '') === 'tool_use');

        return array_values(array_map(fn (array $block): ToolCall => new ToolCall(
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

            if ($blockType === 'web_fetch_tool_result') {
                $result = $block['content'] ?? [];

                if (($result['type'] ?? '') === 'web_fetch_result' && filled($result['url'] ?? null)) {
                    $citations->push(new UrlCitation(
                        $result['url'],
                        $result['content']['title'] ?? null,
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
    protected function extractUsage(array $data): TextUsage
    {
        $usage = $data['usage'] ?? [];
        $cacheReadTokens = $usage['cache_read_input_tokens'] ?? null;
        $cacheWriteTokens = $usage['cache_creation_input_tokens'] ?? null;

        // Anthropic reports input tokens exclusive of the cache buckets...
        return new TextUsage(
            inputTokens: ($usage['input_tokens'] ?? 0) + ($cacheReadTokens ?? 0) + ($cacheWriteTokens ?? 0),
            outputTokens: $usage['output_tokens'] ?? 0,
            cacheReadInputTokens: $cacheReadTokens,
            cacheWriteInputTokens: $cacheWriteTokens,
            reasoningTokens: $usage['output_tokens_details']['thinking_tokens'] ?? null,
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
            'max_tokens', 'model_context_window_exceeded' => FinishReason::Length,
            'refusal' => FinishReason::ContentFilter,
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
