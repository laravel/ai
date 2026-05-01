<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\QueuedAgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFakeAgents
{
    /**
     * All of the registered fake agent gateways.
     */
    protected array $fakeAgentGateways = [];

    /**
     * All of the recorded agent prompts.
     */
    protected array $recordedPrompts = [];

    /**
     * All of the recorded agent prompts that were queued.
     */
    protected array $recordedQueuedPrompts = [];

    /**
     * All of the recorded tool calls, keyed by agent class.
     */
    protected array $recordedToolCalls = [];

    /**
     * Fake the responses returned by the given agent.
     */
    public function fakeAgent(string $agent, Closure|array $responses = []): FakeTextGateway
    {
        return tap(
            new FakeTextGateway($responses),
            fn ($gateway) => $this->fakeAgentGateways[$agent] = $gateway
        );
    }

    /**
     * Determine if the given agent has been faked.
     */
    public function hasFakeGatewayFor(Agent|string $agent): bool
    {
        return array_key_exists(
            is_object($agent) ? $agent::class : $agent,
            $this->fakeAgentGateways
        );
    }

    /**
     * Get a fake gateway instance for the given agent.
     */
    public function fakeGatewayFor(Agent $agent): FakeTextGateway
    {
        return $this->hasFakeGatewayFor($agent)
            ? $this->fakeAgentGateways[$agent::class]
            : throw new InvalidArgumentException('Agent ['.$agent::class.'] has not been faked.');
    }

    /**
     * Record the tool calls made during the given agent's response.
     */
    public function recordToolCalls(string $agent, Collection $toolCalls): self
    {
        foreach ($toolCalls as $toolCall) {
            $this->recordedToolCalls[$agent][] = $toolCall;
        }

        return $this;
    }

    /**
     * Record the given prompt for the faked agent.
     */
    public function recordPrompt(AgentPrompt|QueuedAgentPrompt $prompt): self
    {
        if ($prompt instanceof QueuedAgentPrompt) {
            $this->recordedQueuedPrompts[$prompt->agent::class][] = $prompt;
        } else {
            $this->recordedPrompts[$prompt->agent::class][] = $prompt;
        }

        return $this;
    }

    /**
     * Assert that a prompt was received matching a given truth test.
     */
    public function assertAgentWasPrompted(
        string $agent,
        Closure|string $callback,
        ?array $prompts = null,
        ?string $message = null): self
    {
        $callback = is_string($callback)
            ? fn ($prompt) => $prompt->prompt === $callback
            : $callback;

        PHPUnit::assertTrue(
            (new Collection($prompts ?? $this->recordedPrompts[$agent] ?? []))->contains(function ($prompt) use ($callback) {
                return $callback($prompt);
            }),
            $message ?? 'An expected prompt was not received.'
        );

        return $this;
    }

    /**
     * Assert that a prompt was received matching a given truth test.
     */
    public function assertAgentWasQueued(string $agent, Closure|string $callback): self
    {
        return $this->assertAgentWasPrompted(
            $agent,
            $callback,
            $this->recordedQueuedPrompts[$agent] ?? [],
            'An expected queued prompt was not received.'
        );
    }

    /**
     * Assert that a prompt was not received matching a given truth test.
     */
    public function assertAgentNotPrompted(
        string $agent,
        Closure|string $callback,
        ?array $prompts = null,
        ?string $message = null): self
    {
        $callback = is_string($callback)
            ? fn ($prompt) => $prompt->prompt === $callback
            : $callback;

        PHPUnit::assertTrue(
            (new Collection($prompts ?? $this->recordedPrompts[$agent] ?? []))->doesntContain(function ($prompt) use ($callback) {
                return $callback($prompt);
            }),
            $message ?? 'An unexpected prompt was received.'
        );

        return $this;
    }

    /**
     * Assert that a queued prompt was not received matching a given truth test.
     */
    public function assertAgentNotQueued(string $agent, Closure|string $callback): self
    {
        return $this->assertAgentNotPrompted(
            $agent,
            $callback,
            $this->recordedQueuedPrompts[$agent] ?? [],
            'An unexpected queued prompt was received.'
        );
    }

    /**
     * Assert that no prompts were received.
     */
    public function assertAgentNeverPrompted(string $agent): self
    {
        PHPUnit::assertEmpty(
            $this->recordedPrompts[$agent] ?? [],
            'An unexpected prompt was received.'
        );

        return $this;
    }

    /**
     * Assert that no queued prompts were received.
     */
    public function assertAgentNeverQueued(string $agent): self
    {
        PHPUnit::assertEmpty(
            $this->recordedQueuedPrompts[$agent] ?? [],
            'An unexpected queued prompt was received.'
        );

        return $this;
    }

    /**
     * Assert that the given tool was called by the agent.
     */
    public function assertAgentCalledTool(
        string $agent,
        string $tool,
        Closure|array|null $arguments = null,
    ): self {
        $recorded = new Collection($this->recordedToolCalls[$agent] ?? []);

        $matchingCalls = $recorded->filter(fn (ToolCall $call) => $call->name === $tool);

        PHPUnit::assertTrue(
            $matchingCalls->isNotEmpty(),
            "The expected tool [{$tool}] was not called."
        );

        if (is_null($arguments)) {
            return $this;
        }

        $callback = is_array($arguments)
            ? fn (ToolCall $call) => $this->argumentsContain($call->arguments, $arguments)
            : fn (ToolCall $call) => $arguments($call->arguments, $call);

        PHPUnit::assertTrue(
            $matchingCalls->contains($callback),
            "The tool [{$tool}] was called, but not with the expected arguments."
        );

        return $this;
    }

    /**
     * Assert that the given tool was not called by the agent.
     */
    public function assertAgentDidNotCalledTool(string $agent, string $tool): self
    {
        PHPUnit::assertFalse(
            (new Collection($this->recordedToolCalls[$agent] ?? []))
                ->contains(fn (ToolCall $call) => $call->name === $tool),
            "The unexpected tool [{$tool}] was called."
        );

        return $this;
    }

    /**
     * Assert that no tools were called by the agent.
     */
    public function assertAgentCalledNoTools(string $agent): self
    {
        $calls = new Collection($this->recordedToolCalls[$agent] ?? []);

        PHPUnit::assertTrue(
            $calls->isEmpty(),
            'Expected no tools to be called, but the agent called ['.
                $calls->map(fn (ToolCall $call) => $call->name)->implode(', ').
                '].'
        );

        return $this;
    }

    /**
     * Determine if the recorded arguments contain the expected subset.
     */
    protected function argumentsContain(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }

            if (is_array($value) && is_array($actual[$key])) {
                if (! $this->argumentsContain($actual[$key], $value)) {
                    return false;
                }

                continue;
            }

            if ($actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
