<?php

namespace Laravel\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * A generation step as handed to agent middleware before the model is called; every mutator returns a new instance.
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

    public function agent(): ?Agent
    {
        return $this->options?->agent;
    }

    public function isFirstStep(): bool
    {
        return $this->number === 0;
    }

    public function previousStep(): ?Step
    {
        return $this->steps === [] ? null : $this->steps[array_key_last($this->steps)];
    }

    /**
     * @return string[]
     */
    public function toolNames(): array
    {
        return array_values(array_map(ToolNameResolver::resolve(...), $this->tools));
    }

    public function withModel(string $model): self
    {
        return $this->with(['model' => $model]);
    }

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
     * @param  iterable<Tool|ProviderTool>  $tools
     */
    public function withTools(iterable $tools): self
    {
        return $this->with(['tools' => array_values([...$tools])]);
    }

    public function onlyTools(string ...$names): self
    {
        return $this->withTools(array_filter($this->tools, fn ($tool): bool => in_array(ToolNameResolver::resolve($tool), $names, true)));
    }

    public function withoutTools(string ...$names): self
    {
        return $this->withTools(array_filter($this->tools, fn ($tool): bool => ! in_array(ToolNameResolver::resolve($tool), $names, true)));
    }

    /**
     * @param  ToolChoice|string|array<string, mixed>|null  $toolChoice
     */
    public function withToolChoice(ToolChoice|string|array|null $toolChoice): self
    {
        return $this->withOptions($this->resolvedOptions()->withToolChoice(
            $toolChoice === null ? null : ToolChoice::from($toolChoice),
        ));
    }

    public function withMaxTokens(?int $maxTokens): self
    {
        return $this->withOptions($this->resolvedOptions()->withMaxTokens($maxTokens));
    }

    public function withTemperature(?float $temperature): self
    {
        return $this->withOptions($this->resolvedOptions()->withTemperature($temperature));
    }

    public function withTopP(?float $topP): self
    {
        return $this->withOptions($this->resolvedOptions()->withTopP($topP));
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function withProviderOptions(array $providerOptions): self
    {
        return $this->withOptions($this->resolvedOptions()->withProviderOptions(
            [...($this->options?->providerOptions ?? []), ...$providerOptions],
        ));
    }

    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function withSchema(?array $schema): self
    {
        return $this->with(['schema' => $schema]);
    }

    public function withTimeout(?int $timeout): self
    {
        return $this->with(['timeout' => $timeout]);
    }

    public function withOptions(TextGenerationOptions $options): self
    {
        return $this->with(['options' => $options]);
    }

    protected function resolvedOptions(): TextGenerationOptions
    {
        return $this->options ?? new TextGenerationOptions;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function with(array $overrides): self
    {
        return new self(...[...get_object_vars($this), ...$overrides]);
    }
}
