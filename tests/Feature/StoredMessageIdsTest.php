<?php

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Agents\RememberingAssistantAgent;

test('a remembered turn reports the rows it wrote', function (): void {
    RememberingAssistantAgent::fake(['Hello world']);

    $participant = new class
    {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent)->forUser($participant)->prompt('Hi');

    expect($response->conversationId)->not->toBeNull()
        ->and($response->userMessageId)->not->toBeNull()
        ->and($response->assistantMessageId)->not->toBeNull();

    // The IDs name real rows, not just the stream's own identifiers...
    expect(DB::table('agent_conversation_messages')->where('id', $response->userMessageId)->value('role'))->toBe('user')
        ->and(DB::table('agent_conversation_messages')->where('id', $response->assistantMessageId)->value('role'))->toBe('assistant');
});

test('a streamed turn reports the rows it wrote once it has been consumed', function (): void {
    RememberingAssistantAgent::fake(['Hello world']);

    $participant = new class
    {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent)->forUser($participant)->stream('Hi');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->assistantMessageId)->not->toBeNull()
        ->and(DB::table('agent_conversation_messages')->where('id', $response->assistantMessageId)->value('role'))
        ->toBe('assistant');
});

test('a turn that stores nothing reports no ids', function (): void {
    RememberingAssistantAgent::fake(['Hello world']);

    // No participant and no conversation, so nothing is remembered...
    $response = (new RememberingAssistantAgent)->prompt('Hi');

    expect($response->conversationId)->toBeNull()
        ->and($response->userMessageId)->toBeNull()
        ->and($response->assistantMessageId)->toBeNull();
});
