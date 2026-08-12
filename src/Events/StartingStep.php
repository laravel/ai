<?php

namespace Laravel\Ai\Events;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;

/**
 * Intended for synchronous listeners. A queued listener serializes the whole message history, attachments included, once per step.
 */
class StartingStep
{
    /**
     * @param  string  $model  the model the step is requested against, which the responding model reported on the completed step may differ from
     * @param  Message[]  $messages  the messages being sent for this step, including the tool results of the steps before it
     * @param  TextGenerationOptions|null  $options  the options resolved for this step, which may differ from the agent's own once a forced tool choice has been satisfied
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
    ) {}
}
