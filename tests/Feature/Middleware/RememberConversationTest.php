<?php

use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Collection;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\TextResponse;

function callGenerateTitle(RememberConversation $middleware, string $prompt): string
{
    $method = new ReflectionMethod($middleware, 'generateTitle');

    return $method->invoke($middleware, $prompt);
}

function makeConversationalAgent(object $user): Agent
{
    return (new class implements Agent
    {
        use Promptable;
        use RemembersConversations;

        public function instructions(): Stringable|string
        {
            return 'You are a helpful assistant.';
        }

        public function prompt(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
        {
            throw new RuntimeException('Not used in middleware tests.');
        }

        public function stream(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not used in middleware tests.');
        }

        public function queue(string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
        {
            throw new RuntimeException('Not used in middleware tests.');
        }

        public function broadcast(string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not used in middleware tests.');
        }

        public function broadcastNow(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
        {
            throw new RuntimeException('Not used in middleware tests.');
        }

        public function broadcastOnQueue(string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
        {
            throw new RuntimeException('Not used in middleware tests.');
        }
    })->forUser($user);
}

test('generateTitle uses prompt text when conversation title generation is disabled', function () {
    config(['ai.conversations.generate_title' => false]);

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldNotReceive('textGateway');
    $provider->shouldNotReceive('cheapestTextModel');

    $middleware = new RememberConversation(
        Mockery::mock(ConversationStore::class),
        $provider,
    );

    $title = callGenerateTitle($middleware, str_repeat('word ', 30));

    expect($title)->toBeString()
        ->and(mb_strlen($title))->toBeLessThanOrEqual(50);
});

test('generateTitle falls back to prompt text when provider title generation fails', function () {
    config(['ai.conversations.generate_title' => true]);

    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->once()->andThrow(new RuntimeException('Provider unavailable'));

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->once()->andReturn($gateway);
    $provider->shouldReceive('cheapestTextModel')->once()->andReturn('cheap-model');

    $middleware = new RememberConversation(
        Mockery::mock(ConversationStore::class),
        $provider,
    );

    $prompt = str_repeat('hello world ', 20);
    $title = callGenerateTitle($middleware, $prompt);

    expect($title)->toBeString()
        ->and(mb_strlen($title))->toBeLessThanOrEqual(100);
});

test('generateTitle returns provider generated title when enabled', function () {
    config(['ai.conversations.generate_title' => true]);

    $gateway = Mockery::mock(TextGateway::class);
    $gateway->shouldReceive('generateText')->once()->andReturn(
        new TextResponse(
            'Provider generated title',
            new Usage,
            new Meta('test', 'cheap-model'),
        )
    );

    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('textGateway')->once()->andReturn($gateway);
    $provider->shouldReceive('cheapestTextModel')->once()->andReturn('cheap-model');

    $middleware = new RememberConversation(
        Mockery::mock(ConversationStore::class),
        $provider,
    );

    $title = callGenerateTitle($middleware, 'How do I optimize this Laravel query?');

    expect($title)->toBe('Provider generated title');
});

test('handle creates and persists a new conversation when one does not exist', function () {
    config(['ai.conversations.generate_title' => false]);

    $user = (object) ['id' => 42];
    $agent = makeConversationalAgent($user);

    $provider = Mockery::mock(TextProvider::class);
    $prompt = new AgentPrompt($agent, 'How can I optimize this endpoint?', [], $provider, 'gpt-test');
    $response = new AgentResponse('inv-1', 'Use eager loading.', new Usage, new Meta('openai', 'gpt-test'));

    $store = Mockery::mock(ConversationStore::class);
    $store->shouldReceive('storeConversation')
        ->once()
        ->with(42, Mockery::type('string'))
        ->andReturn('conv-123');
    $store->shouldReceive('storeUserMessage')
        ->once()
        ->with('conv-123', 42, $prompt)
        ->andReturn('msg-user');
    $store->shouldReceive('storeAssistantMessage')
        ->once()
        ->with('conv-123', 42, $prompt, $response)
        ->andReturn('msg-assistant');

    $middleware = new RememberConversation($store, $provider);
    $middleware->handle($prompt, fn () => $response);

    expect($agent->currentConversation())->toBe('conv-123')
        ->and($response->conversationId)->toBe('conv-123')
        ->and($response->conversationUser)->toBe($user);
});

test('handle persists messages without creating conversation when one already exists', function () {
    config(['ai.conversations.generate_title' => false]);

    $user = (object) ['id' => 7];
    $agent = makeConversationalAgent($user)->continue('existing-conv', $user);

    $provider = Mockery::mock(TextProvider::class);
    $prompt = new AgentPrompt($agent, 'Continue this conversation', Collection::make(), $provider, 'gpt-test');
    $response = new AgentResponse('inv-2', 'Continuing now.', new Usage, new Meta('openai', 'gpt-test'));

    $store = Mockery::mock(ConversationStore::class);
    $store->shouldNotReceive('storeConversation');
    $store->shouldReceive('storeUserMessage')
        ->once()
        ->with('existing-conv', 7, $prompt)
        ->andReturn('msg-user-2');
    $store->shouldReceive('storeAssistantMessage')
        ->once()
        ->with('existing-conv', 7, $prompt, $response)
        ->andReturn('msg-assistant-2');

    $middleware = new RememberConversation($store, $provider);
    $middleware->handle($prompt, fn () => $response);

    expect($agent->currentConversation())->toBe('existing-conv')
        ->and($response->conversationId)->toBe('existing-conv')
        ->and($response->conversationUser)->toBe($user);
});
