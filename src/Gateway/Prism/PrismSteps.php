<?php

namespace Laravel\Ai\Gateway\Prism;

use Illuminate\Support\Collection;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\StructuredStep;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Structured\Step as PrismStructuredStep;
use Prism\Prism\Text\Step as PrismTextStep;

class PrismSteps
{
    /**
     * Convert a collection of Prism steps to Laravel AI SDK steps.
     */
    public static function toLaravelSteps(Collection $steps, Provider $provider): Collection
    {
        return $steps->map(fn ($step) => match (true) {
            $step instanceof PrismStructuredStep => static::toLaravelStructuredStep($step, $provider),
            $step instanceof PrismTextStep => static::toLaravelStep($step, $provider),
            default => null,
        })->filter()->values();
    }

    /**
     * Convert a Prism text step to a Laravel AI SDK step.
     */
    public static function toLaravelStep(PrismTextStep $step, Provider $provider): Step
    {
        return new Step(
            $step->text,
            (new Collection($step->toolCalls))->map(PrismTool::toLaravelToolCall(...))->all(),
            (new Collection($step->toolResults))->map(PrismTool::toLaravelToolResult(...))->all(),
            static::toLaravelFinishReason($step->finishReason),
            PrismUsage::toLaravelUsage($step->usage),
            new Meta($provider->name(), $step->meta->model),
        );
    }

    /**
     * Convert a Prism structured step to a Laravel AI SDK structured step.
     */
    public static function toLaravelStructuredStep(PrismStructuredStep $step, Provider $provider): StructuredStep
    {
        return new StructuredStep(
            $step->text,
            $step->structured,
            (new Collection($step->toolCalls))->map(PrismTool::toLaravelToolCall(...))->all(),
            (new Collection($step->toolResults))->map(PrismTool::toLaravelToolResult(...))->all(),
            static::toLaravelFinishReason($step->finishReason),
            PrismUsage::toLaravelUsage($step->usage),
            new Meta($provider->name(), $step->meta->model),
        );
    }

    /**
     * Convert a Prism finish reason to a Laravel AI SDK finish reason.
     */
    public static function toLaravelFinishReason(PrismFinishReason $reason): FinishReason
    {
        return match ($reason) {
            PrismFinishReason::Stop => FinishReason::Stop,
            PrismFinishReason::ToolCalls => FinishReason::ToolCalls,
            PrismFinishReason::Length => FinishReason::Length,
            PrismFinishReason::ContentFilter => FinishReason::ContentFilter,
            PrismFinishReason::Error => FinishReason::Error,
            PrismFinishReason::Other, PrismFinishReason::Unknown => FinishReason::Unknown,
        };
    }
}
