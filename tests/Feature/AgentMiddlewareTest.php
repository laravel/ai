<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\FakeConversationStore;

test('agent middleware is invoked', function () {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $response = (new AssistantAgent)
        ->withMiddleware([middleware()])
        ->prompt('Test prompt');

    expect($response->text)->toEqual('Fake response')
        ->and($_SERVER['__testing.middleware-prompt'])->toBeInstanceOf(AgentPrompt::class);

    unset($_SERVER['__testing.middleware-prompt']);
});

test('agent middleware is invoked when streaming', function () {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $response = (new AssistantAgent)
        ->withMiddleware([middleware()])
        ->stream('Test prompt');

    $response
        ->each(fn () => true)
        ->then(function (StreamedAgentResponse $response) {
            $_SERVER['__testing.text'] = $response->text;
        });

    expect($_SERVER['__testing.text'])->toEqual('Fake response')
        ->and($_SERVER['__testing.middleware-prompt'])->toBeInstanceOf(AgentPrompt::class);

    unset($_SERVER['__testing.text']);
    unset($_SERVER['__testing.middleware-prompt']);
});

test('agent prompted event receives prompt when middleware short circuits', function () {
    Event::fake();

    AssistantAgent::fake([
        'Fake response',
    ]);

    (new AssistantAgent)
        ->withMiddleware([shortCircuitingMiddleware()])
        ->prompt('Test prompt');

    Event::assertDispatched(AgentPrompted::class, function (AgentPrompted $event) {
        return $event->prompt instanceof AgentPrompt
            && $event->prompt->prompt === 'Test prompt';
    });
});

test('stream response conversation id is available after remembered conversations stream completes', function () {
    app()->instance(ConversationStore::class, new FakeConversationStore);

    RememberingAssistantAgent::fake([
        'Fake response',
    ]);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent)->forUser($user)->stream('Test prompt');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->conversationId)->toBe('conversation-123')
        ->and($response->conversationUser)->toBe($user);
});

test('stream response conversation id is available when continuing an existing conversation', function () {
    app()->instance(ConversationStore::class, new FakeConversationStore);

    RememberingAssistantAgent::fake([
        'Fake response',
    ]);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent)
        ->continue('existing-conversation-id', $user)
        ->stream('Test prompt');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->conversationId)->toBe('existing-conversation-id')
        ->and($response->conversationUser)->toBe($user);
});

test('stream response conversation id syncs after late then callbacks', function () {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new AssistantAgent)->stream('Test prompt');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    $response->then(function (StreamedAgentResponse $response) use ($user) {
        $response->withinConversation('late-conversation-id', $user);
    });

    expect($response->conversationId)->toBe('late-conversation-id')
        ->and($response->conversationUser)->toBe($user);
});

test('stream response preserves manually assigned conversation id without a participant', function () {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $response = (new AssistantAgent)
        ->stream('Test prompt')
        ->withinConversation('manual-conversation-id');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->conversationId)->toBe('manual-conversation-id')
        ->and($response->conversationUser)->toBeNull();
});

function shortCircuitingMiddleware(): object
{
    return new class
    {
        public function handle(AgentPrompt $prompt, Closure $next)
        {
            return new AgentResponse(
                'test-invocation-id',
                'Short-circuited response',
                new Usage,
                new Meta,
            );
        }
    };
}

function middleware(): object
{
    return new class
    {
        public function handle(AgentPrompt $prompt, Closure $next)
        {
            $_SERVER['__testing.middleware-prompt'] = $prompt;

            return $next($prompt);
        }
    };
}
