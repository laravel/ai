<?php

namespace Tests\Feature\Stubs;

use Illuminate\Broadcasting\Channel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

final class ConversationStoreStubs
{
    public static function agent(): Agent
    {
        return new class implements Agent {
            public function instructions(): string
            {
                return 'Test agent';
            }

            public function prompt(string $prompt, array $attachments = [], ?string $provider = null, ?string $model = null): AgentResponse
            {
                return new AgentResponse(
                    'invocation-id',
                    '',
                    new \Laravel\Ai\Responses\Data\Usage(),
                    new \Laravel\Ai\Responses\Data\Meta()
                );
            }

            public function stream(string $prompt, array $attachments = [], ?string $provider = null, ?string $model = null): StreamableAgentResponse
            {
                throw new \BadMethodCallException('Not implemented');
            }

            public function queue(string $prompt, array $attachments = [], array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
            {
                throw new \BadMethodCallException('Not implemented');
            }

            public function broadcast(string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, ?string $provider = null, ?string $model = null): StreamableAgentResponse
            {
                throw new \BadMethodCallException('Not implemented');
            }

            public function broadcastNow(string $prompt, Channel|array $channels, array $attachments = [], ?string $provider = null, ?string $model = null): StreamableAgentResponse
            {
                throw new \BadMethodCallException('Not implemented');
            }

            public function broadcastOnQueue(string $prompt, Channel|array $channels, array $attachments = [], ?string $provider = null, ?string $model = null): QueuedAgentResponse
            {
                throw new \BadMethodCallException('Not implemented');
            }
        };
    }

    public static function textProvider(): TextProvider
    {
        return new class(new FakeTextGateway([])) implements TextProvider {
            public function __construct(private TextGateway $gateway) {}

            public function prompt(AgentPrompt $prompt): AgentResponse
            {
                throw new \BadMethodCallException('Not implemented');
            }

            public function stream(AgentPrompt $prompt): StreamableAgentResponse
            {
                throw new \BadMethodCallException('Not implemented');
            }

            public function textGateway(): TextGateway
            {
                return $this->gateway;
            }

            public function useTextGateway(TextGateway $gateway): self
            {
                $this->gateway = $gateway;
                return $this;
            }

            public function defaultTextModel(): string
            {
                return 'test-model';
            }

            public function cheapestTextModel(): string
            {
                return 'test-model';
            }

            public function smartestTextModel(): string
            {
                return 'test-model';
            }
        };
    }
}
