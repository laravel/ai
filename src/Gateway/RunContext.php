<?php

namespace Laravel\Ai\Gateway;

use Closure;
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
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolResult;
use Throwable;

class RunContext
{
    /** @var array<int, Step> */
    protected array $steps = [];

    /**
     * Determine whether the run's delegations are remembered with it, asked as each delegation starts.
     *
     * @var (Closure(): bool)|null
     *
     * @internal
     */
    public ?Closure $remembersDelegations = null;

    /**
     * The conversation each delegation run by this run's tool calls was remembered in, keyed by tool call ID, until the loop collects it.
     *
     * @var array<string, ?string>
     */
    protected array $subConversations = [];

    public function __construct(
        public readonly string $invocationId,
        public readonly Agent $agent,
        public readonly TextProvider $provider,
        public readonly string $model,
        protected readonly Dispatcher $events,
    ) {}

    /**
     * Keep the step the model just produced, so a run that dies later can still be recorded as far as it got.
     */
    public function recordStep(Step $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * Answer the step being worked on, one tool at a time, so a step that dies partway keeps the tools that ran.
     */
    public function recordToolResult(ToolResult $result): void
    {
        $step = array_key_last($this->steps);

        if ($step !== null) {
            $this->steps[$step]->toolResults[] = $result;
        }
    }

    /**
     * Note the conversation the delegation run by the given tool call was remembered in.
     *
     * @internal
     */
    public function linkSubConversation(string $toolCallId, ?string $conversationId): void
    {
        $this->subConversations[$toolCallId] = $conversationId;
    }

    /**
     * Collect the conversation the delegation run by the given tool call was remembered in.
     *
     * @internal
     */
    public function pullSubConversation(string $toolCallId): ?string
    {
        $conversationId = $this->subConversations[$toolCallId] ?? null;

        unset($this->subConversations[$toolCallId]);

        return $conversationId;
    }

    /**
     * The response the run had built by the time it ended, however it ended.
     */
    public function recordedResponse(): AgentResponse
    {
        $last = $this->steps === [] ? null : $this->steps[array_key_last($this->steps)];

        return tap(new AgentResponse(
            $this->invocationId,
            $last?->text ?? '',
            collect($this->steps)->reduce(fn (TextUsage $total, Step $step): TextUsage => $total->add($step->usage), new TextUsage),
            $last?->meta ?? new Meta($this->provider->name(), $this->model),
        ), fn (AgentResponse $response) => $response->withSteps(collect($this->steps)));
    }

    /**
     * Report that a generation step is about to start.
     *
     * @param  Message[]  $messages
     */
    public function startingStep(StepContext $step, array $messages, ?TextGenerationOptions $options, ?string $model = null): void
    {
        $this->events->dispatch(new StartingStep(
            $this->invocationId, $step->stepNumber, $this->agent, $this->provider, $model ?? $this->model, $step->isFinalStep,
            $messages, $options,
        ));
    }

    /**
     * Report that a generation step returned a response.
     */
    public function stepCompleted(?StepContext $step, StepResponse $response, float $time, ?string $model = null): void
    {
        if ($step === null) {
            return;
        }

        $this->events->dispatch(new StepCompleted(
            $this->invocationId, $step->stepNumber, $this->agent, $this->provider, $model ?? $this->model, $step->isFinalStep,
            $response, $time,
        ));
    }

    /**
     * Report that a generation step ended without producing a response.
     */
    public function stepFailed(?StepContext $step, Throwable $exception, float $time, ?string $model = null): void
    {
        if ($step === null) {
            return;
        }

        $this->events->dispatch(new StepFailed(
            $this->invocationId, $step->stepNumber, $this->agent, $this->provider, $model ?? $this->model, $step->isFinalStep,
            $exception, $time,
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
    public function toolInvoked(Tool $tool, array $arguments, mixed $result, string $toolInvocationId, float $time): void
    {
        $this->events->dispatch(new ToolInvoked(
            $this->invocationId, $toolInvocationId, $this->agent, $tool, $arguments, $result, $time,
        ));
    }

    /**
     * Report that a tool's handler threw.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function toolFailed(Tool $tool, array $arguments, Throwable $exception, string $toolInvocationId, float $time): void
    {
        $this->events->dispatch(new ToolFailed(
            $this->invocationId, $toolInvocationId, $this->agent, $tool, $arguments, $exception, $time,
        ));
    }
}
