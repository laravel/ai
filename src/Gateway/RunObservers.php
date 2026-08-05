<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Laravel\Ai\Contracts\Tool;
use Throwable;

// Passed into each run rather than held on the loop, so a nested run cannot overwrite the observers of the run that started it...
class RunObservers
{
    public function __construct(
        public readonly ?string $invocationId = null,
        protected ?Closure $startingStep = null,
        protected ?Closure $stepCompleted = null,
        protected ?Closure $stepFailed = null,
        protected ?Closure $invokingTool = null,
        protected ?Closure $toolInvoked = null,
        protected ?Closure $toolFailed = null,
    ) {}

    /**
     * Observe that a generation step is about to start.
     */
    public function startingStep(StepContext $context): void
    {
        if ($this->startingStep !== null) {
            ($this->startingStep)($context);
        }
    }

    /**
     * Observe that a generation step returned a response.
     */
    public function stepCompleted(StepContext $context, StepResponse $response): void
    {
        if ($this->stepCompleted !== null) {
            ($this->stepCompleted)($context, $response);
        }
    }

    /**
     * Observe that a generation step threw.
     */
    public function stepFailed(StepContext $context, Throwable $exception): void
    {
        if ($this->stepFailed !== null) {
            ($this->stepFailed)($context, $exception);
        }
    }

    /**
     * Observe that a tool is about to be invoked.
     */
    public function invokingTool(Tool $tool, array $arguments, string $toolInvocationId): void
    {
        if ($this->invokingTool !== null) {
            ($this->invokingTool)($tool, $arguments, $toolInvocationId);
        }
    }

    /**
     * Observe that a tool returned a result.
     */
    public function toolInvoked(Tool $tool, array $arguments, mixed $result, string $toolInvocationId): void
    {
        if ($this->toolInvoked !== null) {
            ($this->toolInvoked)($tool, $arguments, $result, $toolInvocationId);
        }
    }

    /**
     * Observe that a tool threw.
     */
    public function toolFailed(Tool $tool, array $arguments, Throwable $exception, string $toolInvocationId): void
    {
        if ($this->toolFailed !== null) {
            ($this->toolFailed)($tool, $arguments, $exception, $toolInvocationId);
        }
    }
}
