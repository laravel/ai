<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Broadcasting\Channel;
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
     * Invoke the agent with a given prompt.
     */
    public function prompt(
        string $prompt,
        array $attachments = [],
        ?string $provider = null,
        ?string $model = null
    ): AgentResponse;

    /**
     * Invoke the agent with a given prompt and return a streamable response.
     */
    public function stream(
        string $prompt,
        array $attachments = [],
        ?string $provider = null,
        ?string $model = null
    ): StreamableAgentResponse;

    /**
     * Invoke the agent in a queued job.
     */
    public function queue(
        string $prompt,
        array $attachments = [],
        array|string|null $provider = null,
        ?string $model = null
    ): QueuedAgentResponse;

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events.
     */
    public function broadcast(
        string $prompt,
        Channel|array $channels,
        array $attachments = [],
        bool $now = false,
        ?string $provider = null,
        ?string $model = null
    ): StreamableAgentResponse;

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events immediately.
     */
    public function broadcastNow(
        string $prompt,
        Channel|array $channels,
        array $attachments = [],
        ?string $provider = null,
        ?string $model = null
    ): StreamableAgentResponse;

    /**
     * Queue the agent with a given prompt and broadcast the streamed events.
     */
    public function broadcastOnQueue(
        string $prompt,
        Channel|array $channels,
        array $attachments = [],
        ?string $provider = null,
        ?string $model = null
    ): QueuedAgentResponse;
}
