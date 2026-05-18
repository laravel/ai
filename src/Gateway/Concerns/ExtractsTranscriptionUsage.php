<?php

namespace Laravel\Ai\Gateway\Concerns;

use Laravel\Ai\Responses\Data\Usage;

trait ExtractsTranscriptionUsage
{
    /**
     * Build usage data from a transcription API response payload.
     */
    protected function transcriptionUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];
        $promptTokens = $usage['input_tokens'] ?? 0;

        $completionTokens = $usage['output_tokens']
            ?? $usage['completion_tokens']
            ?? max(0, ($usage['total_tokens'] ?? 0) - $promptTokens);

        return new Usage($promptTokens, $completionTokens);
    }
}
