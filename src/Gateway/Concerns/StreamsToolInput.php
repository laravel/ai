<?php

namespace Laravel\Ai\Gateway\Concerns;

use Generator;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\ToolInputDelta;
use Laravel\Ai\Streaming\Events\ToolInputStart;

trait StreamsToolInput
{
    /**
     * Get the event that starts streaming a tool call's input, once its ID and name are known.
     *
     * @return Generator<int, ToolInputStart>
     */
    protected function toolInputStart(string $invocationId, ?string $toolId, ?string $toolName): Generator
    {
        if (filled($toolId) && filled($toolName)) {
            yield (new ToolInputStart($this->generateEventId(), $toolId, $toolName, time()))->withInvocationId($invocationId);
        }
    }

    /**
     * Get the event that carries a fragment of a tool call's input.
     *
     * @return Generator<int, ToolInputDelta>
     */
    protected function toolInputDelta(string $invocationId, ?string $toolId, ?string $toolName, string $delta): Generator
    {
        if ($delta !== '' && filled($toolId) && filled($toolName)) {
            yield (new ToolInputDelta($this->generateEventId(), $toolId, $delta, time()))->withInvocationId($invocationId);
        }
    }

    /**
     * Determine if a streamed tool input arrived whole rather than cut off partway.
     */
    protected function isCompleteToolInput(string $input): bool
    {
        return $input === '' || json_validate($input);
    }

    /**
     * Determine if the length limit cut off a tool call's input, leaving the call for the loop to report as failed.
     */
    protected function wasCutOff(string $input, FinishReason $finishReason): bool
    {
        return $finishReason === FinishReason::Length && ! $this->isCompleteToolInput($input);
    }

    /**
     * Generate a unique ID for a stream event.
     */
    abstract protected function generateEventId(): string;
}
