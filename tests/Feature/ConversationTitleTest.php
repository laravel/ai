<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Agents\ConversationTitleAgent;
use Laravel\Ai\Events\AgentPrompted;

beforeEach(function (): void {
    config(['ai.providers.groq' => [...config('ai.providers.groq'), 'key' => 'test-key']]);

    Http::preventStrayRequests();
});

test('the conversation title agent titles a message on the cheapest model', function (): void {
    Event::fake();

    Http::fakeSequence()->pushResponse(fakeGroqResponse('Greeting Conversation'));

    $response = (new ConversationTitleAgent)->prompt('Hello', provider: 'groq');

    expect($response->text)->toBe('Greeting Conversation');

    Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->prompt->agent instanceof ConversationTitleAgent
        && $event->prompt->model === 'openai/gpt-oss-20b'
        && $event->prompt->parentInvocationId === null);
});
