<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Agents\ConversationTitleAgent;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Models\Conversation;
use Tests\Fixtures\Agents\RememberingAssistantAgent;

beforeEach(function (): void {
    config(['ai.providers.groq' => [...config('ai.providers.groq'), 'key' => 'test-key']]);

    Http::preventStrayRequests();
});

test('a title can be generated for a conversation the app created itself', function (): void {
    Event::fake();

    Http::fakeSequence()->pushResponse(fakeGroqResponse('Greeting Conversation'));

    expect(ConversationTitleAgent::titleFor('Hello', Ai::textProvider('groq')))->toBe('Greeting Conversation');

    Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->prompt->agent instanceof ConversationTitleAgent
        && $event->prompt->prompt === 'Hello'
        && $event->prompt->model === 'openai/gpt-oss-20b'
        && $event->prompt->parentInvocationId === null);
});

test('a failed title call falls back to the message', function (): void {
    Http::fakeSequence()->push(status: 500);

    expect(ConversationTitleAgent::titleFor('Hello', 'groq'))->toBe('Hello');
});

test('disabled title generation shortens the message without a title call', function (): void {
    config(['ai.conversations.generate_title' => false]);

    Http::fake();

    expect(ConversationTitleAgent::titleFor(str_repeat('word ', 20), 'groq'))->toBe('word word word word word word word word word...');

    Http::assertNothingSent();
});

test('a faked title agent answers without calling the provider', function (): void {
    ConversationTitleAgent::fake(['Faked Title']);

    Http::fake();

    expect(ConversationTitleAgent::titleFor('Hello', 'groq'))->toBe('Faked Title');

    Http::assertNothingSent();
    ConversationTitleAgent::assertPrompted('Hello');
});

test('a faked conversational agent does not spend its responses on the title', function (): void {
    RememberingAssistantAgent::fake(['First answer', 'Second answer']);

    $user = (object) ['id' => 1];

    $first = (new RememberingAssistantAgent)->forUser($user)->prompt('Hello');
    $second = (new RememberingAssistantAgent)->continue($first->conversationId, as: $user)->prompt('Again');

    expect($second->text)->toBe('Second answer')
        ->and(Conversation::find($first->conversationId)->title)->toBe('Hello');
});

test('a faked conversational agent takes its title from a faked title agent', function (): void {
    RememberingAssistantAgent::fake(['First answer']);
    ConversationTitleAgent::fake(['Greeting Conversation']);

    $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Hello');

    expect($response->text)->toBe('First answer')
        ->and(Conversation::find($response->conversationId)->title)->toBe('Greeting Conversation');
});
