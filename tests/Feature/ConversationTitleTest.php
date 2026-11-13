<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Agents\ConversationTitleAgent;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Models\Conversation;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\Agents\SupportTitleAgent;

beforeEach(function (): void {
    config(['ai.providers.groq' => [...config('ai.providers.groq'), 'key' => 'test-key']]);

    Http::preventStrayRequests();
});

function titleOfConversationStartedWith(string $message): string
{
    $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt($message, provider: 'groq');

    return Conversation::find($response->conversationId)->title;
}

test('a conversation is titled by the title agent on the cheapest model of its provider', function (): void {
    Event::fake();

    Http::fakeSequence()
        ->pushResponse(fakeGroqResponse('Hello there.'))
        ->pushResponse(fakeGroqResponse('Greeting Conversation'));

    $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Hello', provider: 'groq');

    expect(Conversation::find($response->conversationId)->title)->toBe('Greeting Conversation');

    Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->prompt->agent instanceof ConversationTitleAgent
        && $event->prompt->prompt === 'Hello'
        && $event->prompt->model === 'openai/gpt-oss-20b'
        && $event->prompt->parentInvocationId === $response->invocationId);
});

test('a title agent bound in the container titles the conversation', function (): void {
    Event::fake();

    app()->bind(ConversationTitleAgent::class, SupportTitleAgent::class);

    Http::fakeSequence()
        ->pushResponse(fakeGroqResponse('Hello there.'))
        ->pushResponse(fakeGroqResponse('Billing Refund Request'));

    expect(titleOfConversationStartedWith('I want a refund'))->toBe('Billing Refund Request');

    Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->prompt->agent instanceof SupportTitleAgent
        && $event->prompt->model === 'llama-3.1-8b-instant');
});

test('a failed title run falls back to the message', function (): void {
    Http::fakeSequence()
        ->pushResponse(fakeGroqResponse('Hello there.'))
        ->push(status: 500);

    $message = str_repeat('word ', 30);

    expect(titleOfConversationStartedWith($message))->toBe(Str::limit($message, 100, preserveWords: true));
});

test('disabled title generation shortens the message without a title run', function (): void {
    config(['ai.conversations.generate_title' => false]);

    Http::fakeSequence()->pushResponse(fakeGroqResponse('Hello there.'));

    expect(titleOfConversationStartedWith(str_repeat('word ', 20)))->toBe('word word word word word word word word word...');

    Http::assertSentCount(1);
});

test('a faked conversation fakes its title run instead of spending its own responses', function (): void {
    RememberingAssistantAgent::fake(['First answer', 'Second answer']);

    $user = (object) ['id' => 1];

    $first = (new RememberingAssistantAgent)->forUser($user)->prompt('Hello');
    $second = (new RememberingAssistantAgent)->continue($first->conversationId, as: $user)->prompt('Again');

    expect($second->text)->toBe('Second answer')
        ->and(Conversation::find($first->conversationId)->title)->toBe('Fake response for prompt: Hello');

    ConversationTitleAgent::assertPrompted('Hello');
});

test('a faked title agent titles a faked conversation', function (): void {
    RememberingAssistantAgent::fake(['First answer']);
    ConversationTitleAgent::fake(['Greeting Conversation']);

    $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Hello');

    expect($response->text)->toBe('First answer')
        ->and(Conversation::find($response->conversationId)->title)->toBe('Greeting Conversation');
});

test('a bound title agent is faked under its own class', function (): void {
    app()->bind(ConversationTitleAgent::class, SupportTitleAgent::class);

    RememberingAssistantAgent::fake(['First answer']);
    SupportTitleAgent::fake(['Billing Refund Request']);

    expect(titleOfConversationStartedWith('I want a refund'))->toBe('Billing Refund Request');
});
