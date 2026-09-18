<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Concerns\JoinsReasoning;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;

trait ParsesTextResponses
{
    use DecodesStructuredOutput, JoinsReasoning;

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
        $functionCallParts = $this->extractFunctionCallParts($parts);

        return new StepResponse(
            text: $text,
            toolCalls: $this->mapToolCalls($functionCallParts),
            finishReason: $this->extractFinishReason($data, $functionCallParts),
            usage: $this->extractUsage($data),
            meta: new Meta($provider->name(), $model, $this->extractCitations($data)),
            structured: $structured ? $this->decodeStructuredOutput($text) : null,
            replayBlocks: $this->sanitizeRequestParts($this->excludeThinkingParts($parts)),
            reasoning: $this->extractReasoning($parts),
        );
    }

    /**
     * Extract the reasoning text from the response parts.
     */
    protected function extractReasoning(array $parts): string
    {
        $blocks = [];
        $current = '';

        foreach ($parts as $part) {
            if (! isset($part['text'])) {
                continue;
            }

            if ($this->isThinkingPart($part)) {
                $current .= $part['text'];

                continue;
            }

            $blocks[] = $current;
            $current = '';
        }

        return static::joinReasoning([...$blocks, $current]);
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
     * Extract the parts carrying function calls from the response parts.
     */
    protected function extractFunctionCallParts(array $parts): array
    {
        return array_values(
            array_filter($parts, fn (array $part): bool => isset($part['functionCall']))
        );
    }

    /**
     * Map function call parts to ToolCall DTOs.
     *
     * @return array<ToolCall>
     */
    protected function mapToolCalls(array $functionCallParts): array
    {
        return array_map(function (array $part): ToolCall {
            $functionCall = $part['functionCall'];

            $id = $functionCall['id'] ?? (string) Str::uuid7();

            return new ToolCall(
                $id,
                $functionCall['name'] ?? '',
                $functionCall['args'] ?? [],
                $id,
                thoughtSignature: $part['thoughtSignature'] ?? null,
            );
        }, $functionCallParts);
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

        foreach (array_keys($referencedIndices) as $index) {
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
    protected function extractUsage(array $data): TextUsage
    {
        $usage = $data['usageMetadata'] ?? [];

        $reasoningTokens = $usage['thoughtsTokenCount'] ?? null;

        // Gemini reports thought tokens outside the candidate token count...
        return new TextUsage(
            inputTokens: $usage['promptTokenCount'] ?? 0,
            outputTokens: ($usage['candidatesTokenCount'] ?? 0) + ($reasoningTokens ?? 0),
            cacheReadInputTokens: $usage['cachedContentTokenCount'] ?? null,
            reasoningTokens: $reasoningTokens,
        );
    }

    /**
     * Extract usage data from an image generation response.
     */
    protected function extractImageUsage(array $data): ImageUsage
    {
        $usage = $data['usageMetadata'] ?? [];

        $text = $this->extractUsage($data);

        return new ImageUsage(
            $text->inputTokens,
            $text->outputTokens,
            $text->cacheReadInputTokens,
            $text->cacheWriteInputTokens,
            $text->reasoningTokens,
            $this->modalityTokens($usage['promptTokensDetails'] ?? [], 'IMAGE'),
            $this->modalityTokens($usage['candidatesTokensDetails'] ?? [], 'IMAGE'),
        );
    }

    /**
     * Get the token count Gemini reported for the given modality.
     */
    protected function modalityTokens(array $details, string $modality): ?int
    {
        return collect($details)->firstWhere('modality', $modality)['tokenCount'] ?? null;
    }

    /**
     * Extract and map the finish reason from the Gemini response.
     */
    protected function extractFinishReason(array $data, array $functionCallParts): FinishReason
    {
        if (filled($functionCallParts)) {
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
