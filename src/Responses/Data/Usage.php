<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

class Usage implements Arrayable, JsonSerializable
{
    public function __construct(
        int|array|null $inputTokens = [],
        int|array|null $outputTokens = [],
        int|array|null $cachedTokens = [],
        int|array|null $toolsTokens = [],
        ?int $reasoningTokens = null,
        ?int $cacheWriteInputTokens = null,
        ?int $cacheReadInputTokens = null,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
    ) {
        $this->inputTokens = $this->normalizeTokenCollection($inputTokens);
        $this->outputTokens = $this->normalizeTokenCollection($outputTokens);
        $this->cachedTokens = $this->normalizeTokenCollection($cachedTokens);
        $this->toolsTokens = $this->normalizeTokenCollection($toolsTokens);

        // Backward compatibility with legacy scalar constructor arguments.
        if ($promptTokens !== null) {
            $this->inputTokens['text'] = $promptTokens;
        }

        if ($completionTokens !== null) {
            $this->outputTokens['text'] = $completionTokens;
        }

        if ($reasoningTokens !== null) {
            $this->outputTokens['reasoning'] = $reasoningTokens;
        }

        if ($cacheWriteInputTokens !== null) {
            $this->cachedTokens['write'] = $cacheWriteInputTokens;
        }

        if ($cacheReadInputTokens !== null) {
            $this->cachedTokens['read'] = $cacheReadInputTokens;
        }

        // Backward compatibility for positional old constructor:
        // Usage(prompt, completion, cacheWrite, cacheRead, reasoning)
        if (is_int($cachedTokens) && $cachedTokens !== 0 && ! array_key_exists('write', $this->cachedTokens)) {
            $this->cachedTokens['write'] = $cachedTokens;
        }

        if (is_int($toolsTokens) && $toolsTokens !== 0 && ! array_key_exists('read', $this->cachedTokens)) {
            $this->cachedTokens['read'] = $toolsTokens;
            $this->toolsTokens = [];
        }
    }

    public array $inputTokens;

    public array $outputTokens;

    public array $cachedTokens;

    public array $toolsTokens;

    public function __get(string $name): mixed
    {
        return match ($name) {
            'promptTokens' => array_sum($this->inputTokens),
            'completionTokens' => array_sum(array_diff_key($this->outputTokens, ['reasoning' => true])),
            'reasoningTokens' => $this->outputTokens['reasoning'] ?? 0,
            'cacheWriteInputTokens' => $this->cachedTokens['write'] ?? 0,
            'cacheReadInputTokens' => $this->cachedTokens['read'] ?? array_sum($this->cachedTokens),
            default => null,
        };
    }

    /**
     * Add the given usage to the current usage and return a new usage instance.
     */
    public function add(Usage $usage): Usage
    {
        return new Usage(
            $this->accumulate($this->inputTokens, $usage->inputTokens),
            $this->accumulate($this->outputTokens, $usage->outputTokens),
            $this->accumulate($this->cachedTokens, $usage->cachedTokens),
            $this->accumulate($this->toolsTokens, $usage->toolsTokens),
        );
    }

    /** 
     * Accumulates elements from two collections.
     */
    private function accumulate(array $a1, array $a2): array
    {
        $result = [];
        foreach (array_keys($a1 + $a2) as $key) $result[$key] = ($a1[$key] ?? 0) + ($a2[$key] ?? 0);
        return $result;
    }

    /**
     * Normalize scalar or null usage values to modality-indexed arrays.
     */
    private function normalizeTokenCollection(int|array|null $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null) {
            return [];
        }

        return ['text' => $value];
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            // New modality-indexed structure
            'input_tokens' => $this->includeTotals($this->inputTokens),
            'output_tokens' => $this->includeTotals($this->outputTokens),
            'cached_tokens' => $this->includeTotals($this->cachedTokens),
            'tools_tokens' => $this->includeTotals($this->toolsTokens),
            // Backward compatibility - legacy scalar field names
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }

    /**
     * Include totals in each usage array.
     */
    private function includeTotals($data): array
    {
        return [
            'total' => collect($data)->values()->sum(),
            ...$data,
        ];
    }


    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

}
