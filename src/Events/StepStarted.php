<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;

class StepStarted
{
    /**
     * @param  array<int, mixed>  $messages  Conversation history sent to the model for this step.
     * @param  array<int, Tool>  $tools  Tool definitions available this step.
     */
    public function __construct(
        public string $invocationId,
        public string $stepId,
        public int $stepNumber,
        public string $model,
        public array $messages = [],
        public array $tools = [],
        public ?TextGenerationOptions $options = null,
    ) {}
}
