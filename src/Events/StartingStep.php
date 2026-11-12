<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;

class StartingStep
{
    /**
     * @param  string  $model  The model the step is requested against, which the responding model reported on the completed step may differ from.
     * @param  Message[]  $messages  The messages being sent for this step, including the tool results of the steps before it.
     * @param  TextGenerationOptions|null  $options  The options resolved for this step, which may differ from the agent's own once a forced tool choice has been satisfied.
     * @param  string|null  $parentInvocationId  The invocation that delegated this run through a tool, null for a root run.
     * @param  string|null  $parentToolInvocationId  The tool invocation that delegated this run, null for a root run.
     */
    public function __construct(
        public string $invocationId,
        public int $stepNumber,
        public Agent $agent,
        public TextProvider $provider,
        public string $model,
        public bool $isFinalStep,
        public array $messages,
        public ?TextGenerationOptions $options,
        public ?string $parentInvocationId = null,
        public ?string $parentToolInvocationId = null,
    ) {}
}
