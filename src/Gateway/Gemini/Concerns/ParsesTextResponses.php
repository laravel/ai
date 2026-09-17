<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\Concerns\MergesCitations;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

trait ParsesTextResponses
{
    use DecodesStructuredOutput, MergesCitations;

    /**
     * Validate the Gemini response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'Gemini Error: [%s] %s',
                $data['error']['code'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown Gemini error.',
            ));
        }
    }

    /**
     * Parse the Gemini response data into a single step response.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        string $model,
        bool $structured,
    ): StepResponse {
        $candidate = $data['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];

        $text = $this->extractText($parts);
        $rawToolCalls = $this->extractRawToolCalls($parts);

        return new StepResponse(
            text: $text,
            toolCalls: $this->mapToolCalls($rawToolCalls),
            finishReason: $this->extractFinishReason($data, $rawToolCalls),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model, $this->extractCitations($data, $parts)),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            providerContentBlocks: $this->sanitizeRequestParts($this->excludeThinkingParts($parts)),
        );
    }

    /**
     * Determine if a response part is a thinking/thought part.
     */
    protected function isThinkingPart(array $part): bool
    {
        return $part['thought'] ?? false;
    }

    /**
     * Sanitize functionCall parts so they can be sent back to Gemini as conversation history.
     */
    protected function sanitizeRequestParts(array $parts): array
    {
        return array_map(function (array $part) {
            if (! isset($part['functionCall'])) {
                return $part;
            }

            $functionCall = ['name' => $part['functionCall']['name'] ?? ''];

            $args = $part['functionCall']['args'] ?? null;

            if (filled($args)) {
                $functionCall['args'] = $args;
            }

            $part['functionCall'] = $functionCall;

            return $part;
        }, $parts);
    }

    /**
     * Filter out thinking parts from the response, keeping only text and functionCall parts.
     */
    protected function excludeThinkingParts(array $parts): array
    {
        return array_values(array_filter(
            $parts,
            fn (array $part): bool => ! $this->isThinkingPart($part),
        ));
    }

    /**
     * Extract the text content from the response parts, excluding thinking parts.
     */
    protected function extractText(array $parts): string
    {
        $textParts = [];

        foreach ($parts as $part) {
            if (isset($part['text']) && ! $this->isThinkingPart($part)) {
                $textParts[] = $part['text'];
            }
        }

        return implode('', $textParts);
    }

    /**
     * Extract raw tool calls from the response parts.
     */
    protected function extractRawToolCalls(array $parts): array
    {
        return array_values(
            array_map(
                fn (array $part) => $part['functionCall'],
                array_filter($parts, fn (array $part): bool => isset($part['functionCall']))
            )
        );
    }

    /**
     * Map raw function call data to ToolCall DTOs.
     *
     * @return array<ToolCall>
     */
    protected function mapToolCalls(array $rawToolCalls): array
    {
        return array_map(function (array $fc): ToolCall {
            $id = $fc['id'] ?? (string) Str::uuid7();

            return new ToolCall(
                $id,
                $fc['name'] ?? '',
                $fc['args'] ?? [],
                $id,
            );
        }, $rawToolCalls);
    }

    /**
     * Extract citations from the response data.
     *
     * @param  array<int, array<string, mixed>>  $parts
     */
    protected function extractCitations(array $data, array $parts = []): Collection
    {
        $citations = new Collection;

        $candidate = $data['candidates'][0] ?? [];

        $text = $this->extractText($parts);
        $partOffsets = $this->partByteOffsets($parts);

        // Legacy citation metadata format...
        $sources = $candidate['citationMetadata']['citationSources'] ?? [];

        foreach ($sources as $source) {
            if (! isset($source['uri'])) {
                continue;
            }

            $this->mergeCitation($citations, $source['uri'], $source['title'] ?? null)->addRange(
                $this->toCharacterOffset($text, $source['startIndex'] ?? null),
                $this->toCharacterOffset($text, $source['endIndex'] ?? null),
            );
        }

        // Grounding metadata format (Google Search grounding)...
        $groundingSupports = $candidate['groundingMetadata']['groundingSupports'] ?? [];
        $groundingChunks = $candidate['groundingMetadata']['groundingChunks'] ?? [];

        foreach ($groundingSupports as $support) {
            $segment = $support['segment'] ?? [];
            $base = $partOffsets[$segment['partIndex'] ?? 0] ?? 0;

            foreach ($support['groundingChunkIndices'] ?? [] as $index) {
                $web = $groundingChunks[$index]['web'] ?? [];

                if (! isset($web['uri'])) {
                    continue;
                }

                $this->mergeCitation($citations, $web['uri'], $web['title'] ?? null)->addRange(
                    $this->toCharacterOffset($text, $segment['startIndex'] ?? null, $base),
                    $this->toCharacterOffset($text, $segment['endIndex'] ?? null, $base),
                );
            }
        }

        return $citations->values();
    }

    /**
     * Map each part index to the byte offset where its text begins within the response text.
     *
     * @param  array<int, array<string, mixed>>  $parts
     * @return array<int, int>
     */
    protected function partByteOffsets(array $parts): array
    {
        $offsets = [];
        $cursor = 0;

        foreach ($parts as $index => $part) {
            $offsets[$index] = $cursor;

            if (isset($part['text']) && ! $this->isThinkingPart($part)) {
                $cursor += strlen($part['text']);
            }
        }

        return $offsets;
    }

    /**
     * Convert a Gemini byte offset, relative to the part it was reported against, into a character offset.
     */
    protected function toCharacterOffset(string $text, ?int $byteOffset, int $partOffset = 0): ?int
    {
        if ($byteOffset === null || $text === '') {
            return $byteOffset;
        }

        return mb_strlen(substr($text, 0, $partOffset + $byteOffset));
    }

    /**
     * Extract usage data from the response.
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usageMetadata'] ?? [];

        $promptTokens = $usage['promptTokenCount'] ?? 0;
        $cachedTokens = $usage['cachedContentTokenCount'] ?? 0;

        return new Usage(
            $promptTokens - $cachedTokens,
            $usage['candidatesTokenCount'] ?? 0,
            0,
            $cachedTokens,
            $usage['thoughtsTokenCount'] ?? 0,
        );
    }

    /**
     * Extract and map the finish reason from the Gemini response.
     */
    protected function extractFinishReason(array $data, array $rawToolCalls): FinishReason
    {
        if (filled($rawToolCalls)) {
            return FinishReason::ToolCalls;
        }

        $candidate = $data['candidates'][0] ?? [];
        $reason = $candidate['finishReason'] ?? '';

        return match ($reason) {
            'STOP' => FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'MALFORMED_FUNCTION_CALL' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }
}
