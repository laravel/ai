<?php

use Illuminate\Support\Collection;
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
    app()->instance(ConversationStore::class, new class implements ConversationStore
    {
        public function latestConversationId(string|int $userId): ?string
        {
            return null;
        }

        public function storeConversation(string|int|null $userId, string $title): string
        {
            return 'conversation-123';
        }

        public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
        {
            return 'user-message-123';
        }

        public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
        {
            return 'assistant-message-123';
        }

        public function getLatestConversationMessages(string $conversationId, int $limit): Collection
        {
            return new Collection;
        }
    });

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

    expect($response->conversationId)->not->toBeNull()
        ->and($response->conversationUser)->toBe($user);
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
