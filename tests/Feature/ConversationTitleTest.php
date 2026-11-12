<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Models\Conversation;

beforeEach(function (): void {
    config(['ai.providers.groq' => [...config('ai.providers.groq'), 'key' => 'test-key']]);

    Http::preventStrayRequests();
});

test('a title can be generated for a conversation the app created itself', function (): void {
    Event::fake();

    Http::fakeSequence()->pushResponse(fakeGroqResponse('Greeting Conversation'));

    expect(Conversation::generateTitle('Hello', Ai::textProvider('groq')))->toBe('Greeting Conversation');

    Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->prompt->prompt === 'Hello'
        && $event->prompt->model === 'openai/gpt-oss-20b'
        && $event->prompt->parentInvocationId === null);
});

test('a failed title call falls back to the message', function (): void {
    Http::fakeSequence()->push(status: 500);

    expect(Conversation::generateTitle('Hello', Ai::textProvider('groq')))->toBe('Hello');
});

test('disabled title generation shortens the message without a title call', function (): void {
    config(['ai.conversations.generate_title' => false]);

    Http::fake();

    expect(Conversation::generateTitle(str_repeat('word ', 20), Ai::textProvider('groq')))->toBe('word word word word word word word word word...');

    Http::assertNothingSent();
});
