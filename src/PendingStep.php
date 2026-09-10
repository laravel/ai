<?php

namespace Laravel\Ai;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * A generation step handed to agent middleware before the model is called.
 */
class PendingStep
{
    /**
     * @param  Message[]  $messages
     * @param  array<Tool|ProviderTool>  $tools
     * @param  array<string, mixed>|null  $schema
     * @param  Step[]  $steps  The steps completed so far in this run.
     * @param  Usage  $usage  The usage accumulated by the completed steps.
     */
    public function __construct(
        public readonly int $number,
        public readonly bool $isFinalStep,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?string $instructions,
        public readonly array $messages,
        public readonly array $tools,
        public readonly ?array $schema,
        public readonly ?TextGenerationOptions $options,
        public readonly array $steps = [],
        public readonly Usage $usage = new Usage,
        public readonly ?int $timeout = null,
        public readonly ?string $invocationId = null,
    ) {}

    /**
     * Determine whether this is the first generation step.
     */
    public function isFirstStep(): bool
    {
        return $this->number === 0;
    }

    /**
     * Create a copy using a different model.
     */
    public function withModel(string $model): self
    {
        return $this->with(['model' => $model]);
    }

    /**
     * Create a copy using different instructions.
     */
    public function withInstructions(?string $instructions): self
    {
        return $this->with(['instructions' => $instructions]);
    }

    /**
     * Replaces the history sent for this step only; the run's history still grows from the original.
     *
     * @param  iterable<Message>  $messages
     */
    public function withMessages(iterable $messages): self
    {
        return $this->with(['messages' => array_values([...$messages])]);
    }

    /**
     * Create a copy using the given tools.
     *
     * @param  iterable<Tool|ProviderTool>  $tools
     */
    public function withTools(iterable $tools): self
    {
        return $this->with(['tools' => array_values([...$tools])]);
    }

    /**
     * Create a copy containing only the named tools.
     */
    public function onlyTools(string ...$names): self
    {
        return $this->withTools(array_filter($this->tools, fn ($tool): bool => in_array(ToolNameResolver::resolve($tool), $names, true)));
    }

    /**
     * Create a copy excluding the named tools.
     */
    public function withoutTools(string ...$names): self
    {
        return $this->withTools(array_filter($this->tools, fn ($tool): bool => ! in_array(ToolNameResolver::resolve($tool), $names, true)));
    }

    /**
     * Create a copy using a different tool choice.
     *
     * @param  ToolChoice|string|array<string, mixed>|null  $toolChoice
     */
    public function withToolChoice(ToolChoice|string|array|null $toolChoice): self
    {
        return $this->withOptions($this->resolvedOptions()->withToolChoice(
            $toolChoice === null ? null : ToolChoice::from($toolChoice),
        ));
    }

    /**
     * Create a copy using a different maximum token count.
     */
    public function withMaxTokens(?int $maxTokens): self
    {
        return $this->withOptions($this->resolvedOptions()->withMaxTokens($maxTokens));
    }

    /**
     * Create a copy using the given provider options.
     *
     * @param  array<string, mixed>  $providerOptions
     */
    public function withProviderOptions(array $providerOptions): self
    {
        return $this->withOptions($this->resolvedOptions()->withProviderOptions(
            [...($this->options?->providerOptions ?? []), ...$providerOptions],
        ));
    }

    /**
     * Create a copy using the given options.
     */
    protected function withOptions(TextGenerationOptions $options): self
    {
        return $this->with(['options' => $options]);
    }

    /**
     * Get the step options, creating an empty set when none were provided.
     */
    protected function resolvedOptions(): TextGenerationOptions
    {
        return $this->options ?? new TextGenerationOptions;
    }

    /**
     * Create a copy with the given property overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function with(array $overrides): self
    {
        return new self(...[...get_object_vars($this), ...$overrides]);
    }
}
