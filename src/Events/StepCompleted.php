<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

class StepCompleted
{
    /**
     * @param  string  $model  the model the step was requested against, which the responding model reported in $meta may differ from
     * @param  ToolCall[]  $toolCalls
     * @param  float|null  $durationMs  wall time spent in the provider call, or null when the step was not measured
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Agent $agent,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
        public Meta $meta,
        public Usage $usage,
        public FinishReason $finishReason,
        public array $toolCalls,
        public ?float $durationMs = null,
    ) {}
}
