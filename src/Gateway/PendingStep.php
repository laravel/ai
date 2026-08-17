<?php

namespace Laravel\Ai\Gateway;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Tools\ToolResolver;

class PendingStep
{
    /**
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, mixed>|null  $schema
     */
    public function __construct(
        public readonly TextProvider $provider,
        public readonly string $model,
        public readonly ?string $instructions,
        public readonly array $messages,
        public readonly array $tools,
        public readonly ?array $schema,
        public readonly ?TextGenerationOptions $options,
        public readonly ?int $timeout,
        public readonly StepContext $context,
        public readonly bool $streaming = false,
    ) {}

    /**
     * Change the messages, returning a new pending step instance.
     *
     * @param  Message[]  $messages
     */
    public function withMessages(array $messages): PendingStep
    {
        return $this->copy(['messages' => $messages]);
    }

    /**
     * Change the instructions, returning a new pending step instance.
     */
    public function withInstructions(?string $instructions): PendingStep
    {
        return $this->copy(['instructions' => $instructions]);
    }

    /**
     * Change the tools, returning a new pending step instance.
     *
     * @param  array<int, mixed>  $tools
     */
    public function withTools(array $tools): PendingStep
    {
        return $this->copy([
            'tools' => array_map(fn ($tool) => ToolResolver::resolve($tool), $tools),
        ]);
    }

    /**
     * Change the model, returning a new pending step instance.
     */
    public function withModel(string $model): PendingStep
    {
        return $this->copy(['model' => $model]);
    }

    /**
     * Change the structured output schema, returning a new pending step instance.
     *
     * @param  array<string, mixed>|null  $schema
     */
    public function withSchema(?array $schema): PendingStep
    {
        return $this->copy(['schema' => $schema]);
    }

    /**
     * Change the request timeout, returning a new pending step instance.
     */
    public function withTimeout(?int $timeout): PendingStep
    {
        return $this->copy(['timeout' => $timeout]);
    }

    /**
     * Change the given generation options, returning a new pending step instance.
     */
    public function withOptions(mixed ...$options): PendingStep
    {
        return $this->copy([
            'options' => ($this->options ?? new TextGenerationOptions)->with(...$options),
        ]);
    }

    /**
     * Create a copy of the pending step with the given attribute overrides.
     */
    protected function copy(array $attributes): PendingStep
    {
        return new self(...array_merge(get_object_vars($this), $attributes));
    }
}
