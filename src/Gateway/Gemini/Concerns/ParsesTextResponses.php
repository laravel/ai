<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
     * Parse a single Gemini response into a SingleTurnResponse.
     */
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        string $model,
        bool $structured,
    ): SingleTurnResponse {
        $candidate = $data['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];

        $text = $this->extractText($parts);
        $rawToolCalls = $this->extractRawToolCalls($parts);
        $citations = $this->extractCitations($data);
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($data, $rawToolCalls);
        $mappedToolCalls = $this->mapToolCalls($rawToolCalls);

        return new SingleTurnResponse(
            text: $text,
            toolCalls: $mappedToolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model, $citations),
            structured: $structured ? (json_decode($text, true) ?? []) : null,
            responseId: null,
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
                array_filter($parts, fn (array $part) => isset($part['functionCall']))
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
        return array_map(function (array $fc) {
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
     */
    protected function extractCitations(array $data): Collection
    {
        $citations = new Collection;

        $candidate = $data['candidates'][0] ?? [];

        // Legacy citation metadata format...
        $sources = $candidate['citationMetadata']['citationSources'] ?? [];

        foreach ($sources as $source) {
            if (isset($source['uri'])) {
                $citations->push(new UrlCitation(
                    $source['uri'],
                    $source['title'] ?? null,
                ));
            }
        }

        // Grounding metadata format (Google Search grounding)...
        $groundingChunks = $candidate['groundingMetadata']['groundingChunks'] ?? [];
        $groundingSupports = $candidate['groundingMetadata']['groundingSupports'] ?? [];

        $referencedIndices = [];

        foreach ($groundingSupports as $support) {
            foreach ($support['groundingChunkIndices'] ?? [] as $index) {
                $referencedIndices[$index] = true;
            }
        }

        foreach ($referencedIndices as $index => $_) {
            $web = $groundingChunks[$index]['web'] ?? [];

            if (isset($web['uri'])) {
                $citations->push(new UrlCitation(
                    $web['uri'],
                    $web['title'] ?? null,
                ));
            }
        }

        return $citations->unique('url')->values();
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
