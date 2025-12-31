<?php

namespace Laravel\Ai\Concerns;

use Closure;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\AgentPrompt;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Gateway\FakeGateway;
use Laravel\Ai\QueuedAgentPrompt;
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
     * Fake the responses returned by the given agent.
     */
    public function fakeAgent(string $agent, Closure|array $responses = []): FakeGateway
    {
        return tap(
            new FakeGateway($responses),
            fn ($gateway) => $this->fakeAgentGateways[$agent] = $gateway
        );
    }

    /**
     * Determine if the given agent has been faked.
     */
    public function hasFakeGatewayFor(Agent|string $agent): bool
    {
        return array_key_exists(
            is_object($agent) ? get_class($agent) : $agent,
            $this->fakeAgentGateways
        );
    }

    /**
     * Get a fake gateway instance for the given agent.
     */
    public function fakeGatewayFor(Agent $agent): FakeGateway
    {
        return $this->hasFakeGatewayFor($agent)
            ? $this->fakeAgentGateways[get_class($agent)]
            : throw new InvalidArgumentException('Agent ['.get_class($agent).'] has not been faked.');
    }

    /**
     * Record the given prompt for the faked agent.
     */
    public function recordPrompt(AgentPrompt|QueuedAgentPrompt $prompt, bool $queued = false): self
    {
        if ($queued) {
            $this->recordedQueuedPrompts[get_class($prompt->agent)][] = $prompt;
        } else {
            $this->recordedPrompts[get_class($prompt->agent)][] = $prompt;
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
            (new Collection($prompts ?? $this->recordedPrompts[$agent] ?? []))->filter(function ($prompt) use ($callback) {
                return $callback($prompt);
            })->count() > 0,
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
            $this->recordedQueuedPrompts[$agent],
            'An expected queued prompt was not received.'
        );
    }

    /**
     * Assert that a prompt was received a given number of times matching a given truth test.
     */
    public function assertAgentWasPromptedTimes(string $agent, Closure|string $callback, int $times = 1): self
    {
        $callback = is_string($callback)
            ? fn (AgentPrompt $prompt) => $prompt->prompt === $callback
            : $callback;

        $count = (new Collection($this->recordedPrompts[$agent] ?? []))
            ->filter(fn ($prompt) => $callback($prompt))
            ->count();

        PHPUnit::assertSame(
            $times, $count,
            "An expected prompt was received {$count} times instead of {$times} times."
        );

        return $this;
    }

    /**
     * Assert that a prompt was not received matching a given truth test.
     */
    public function assertAgentWasntPrompted(
        string $agent,
        Closure|string $callback,
        ?array $prompts = null,
        ?string $message = null): self
    {
        $callback = is_string($callback)
            ? fn ($prompt) => $prompt->prompt === $callback
            : $callback;

        PHPUnit::assertTrue(
            (new Collection($prompts ?? $this->recordedPrompts[$agent] ?? []))->filter(function ($prompt) use ($callback) {
                return $callback($prompt);
            })->count() === 0,
            $message ?? 'An unexpected prompt was received.'
        );

        return $this;
    }

    /**
     * Assert that a queued prompt was not received matching a given truth test.
     */
    public function assertAgentWasntQueued(string $agent, Closure|string $callback): self
    {
        return $this->assertAgentWasntPrompted(
            $agent,
            $callback,
            $this->recordedQueuedPrompts[$agent],
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
}
