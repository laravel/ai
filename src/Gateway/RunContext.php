<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Streaming\Events\Error;
use Throwable;

class RunContext
{
    public function __construct(
        public readonly string $invocationId,
        public readonly Agent $agent,
        public readonly TextProvider $provider,
        public readonly string $model,
        protected readonly Dispatcher $events,
    ) {}

    /**
     * Report that a generation step is about to start.
     */
    public function startingStep(StepContext $step): void
    {
        $this->events->dispatch(new StartingStep(
            $this->invocationId, $step->stepNumber, $this->agent, $this->provider, $this->model, $step->isFinalStep,
        ));
    }

    /**
     * Report that a generation step returned a response.
     */
    public function stepCompleted(StepContext $step, StepResponse $response, float $durationMs): void
    {
        $this->events->dispatch(new StepCompleted(
            $this->invocationId, $step->stepNumber, $this->agent, $this->provider, $this->model, $step->isFinalStep,
            $response->meta, $response->usage, $response->finishReason, $response->toolCalls, $durationMs,
        ));
    }

    /**
     * Report that a generation step ended without producing a response.
     */
    public function stepFailed(StepContext $step, Throwable $exception, float $durationMs, ?Error $error = null): void
    {
        $this->events->dispatch(new StepFailed(
            $this->invocationId, $step->stepNumber, $this->agent, $this->provider, $this->model, $step->isFinalStep,
            $exception, $durationMs, $error,
        ));
    }

    /**
     * Report that a tool is about to be invoked.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function invokingTool(Tool $tool, array $arguments, string $toolInvocationId): void
    {
        $this->events->dispatch(new InvokingTool(
            $this->invocationId, $toolInvocationId, $this->agent, $tool, $arguments,
        ));
    }

    /**
     * Report that a tool returned a result.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function toolInvoked(Tool $tool, array $arguments, mixed $result, string $toolInvocationId, float $durationMs): void
    {
        $this->events->dispatch(new ToolInvoked(
            $this->invocationId, $toolInvocationId, $this->agent, $tool, $arguments, $result, $durationMs,
        ));
    }

    /**
     * Report that a tool's handler threw.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function toolFailed(Tool $tool, array $arguments, Throwable $exception, string $toolInvocationId, float $durationMs): void
    {
        $this->events->dispatch(new ToolFailed(
            $this->invocationId, $toolInvocationId, $this->agent, $tool, $arguments, $exception, $durationMs,
        ));
    }
}
