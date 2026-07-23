<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Stringable;

interface Agent
{
    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string;

    /**
     * Invoke the agent with a given prompt, or resume a paused run with tool approval decisions.
     */
    public function prompt(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse;

    /**
     * Invoke the agent with a given prompt and return a streamable response.
     */
    public function stream(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StreamableAgentResponse;

    /**
     * Invoke the agent in a queued job.
     */
    public function queue(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null
    ): QueuedAgentResponse;

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events.
     */
    public function broadcast(
        Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        bool $now = false,
        Lab|array|string|null $provider = null,
        ?string $model = null
    ): StreamableAgentResponse;

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events immediately.
     */
    public function broadcastNow(
        Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null
    ): StreamableAgentResponse;

    /**
     * Queue the agent with a given prompt and broadcast the streamed events.
     */
    public function broadcastOnQueue(
        Decisions|string $prompt,
        Channel|array $channels,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null
    ): QueuedAgentResponse;

    /**
     * Broadcast a static message without invoking the agent.
     */
    public function broadcastMessage(
        string $message,
        Channel|array $channels,
        bool $now = false
    ): void;

    /**
     * Broadcast a static message immediately without invoking the agent.
     */
    public function broadcastMessageNow(
        string $message,
        Channel|array $channels
    ): void;

    /**
     * Queue a static message for broadcasting without invoking the agent.
     */
    public function broadcastMessageOnQueue(
        string $message,
        Channel|array $channels
    ): QueuedAgentResponse;
}
