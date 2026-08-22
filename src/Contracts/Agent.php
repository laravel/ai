<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\RealtimeSession;
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
     * Create an ephemeral realtime session for the agent.
     */
    public function realtime(
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?string $voice = null,
        array $options = [],
        ?int $timeout = null,
    ): RealtimeSession;

    /**
     * Create ephemeral client credentials for the agent.
     */
    public function clientCredentials(
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?string $voice = null,
        array $options = [],
        ?int $timeout = null,
    ): RealtimeSession;
}
