<?php

namespace Laravel\Ai\Responses\Data;

readonly class ImageUsage extends TextUsage
{
    /**
     * @param  int|null  $imageInputTokens  Subset of the input tokens billed for images, or null when unreported.
     * @param  int|null  $imageOutputTokens  Subset of the output tokens billed for generated images, or null when unreported.
     */
    public function __construct(
        int $inputTokens = 0,
        int $outputTokens = 0,
        ?int $cacheReadInputTokens = null,
        ?int $cacheWriteInputTokens = null,
        ?int $reasoningTokens = null,
        public ?int $imageInputTokens = null,
        public ?int $imageOutputTokens = null,
    ) {
        parent::__construct($inputTokens, $outputTokens, $cacheReadInputTokens, $cacheWriteInputTokens, $reasoningTokens);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'image_input_tokens' => $this->imageInputTokens,
            'image_output_tokens' => $this->imageOutputTokens,
        ];
    }
}
