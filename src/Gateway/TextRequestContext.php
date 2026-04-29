<?php

namespace Laravel\Ai\Gateway;

class TextRequestContext
{
    public array $contents = [];

    public array $requestBody = [];

    public array $providerMessages = [];

    public function __construct(
        public string $model,
        public ?string $instructions,
        public array $messages,
        public array $tools,
        public ?array $schema,
        public ?TextGenerationOptions $options,
        public ?int $timeout,
    ) {}

    public static function fromGenerateTextArgs(
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): self {
        return new self($model, $instructions, $messages, $tools, $schema, $options, $timeout);
    }
}
