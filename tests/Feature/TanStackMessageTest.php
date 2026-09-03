<?php

use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\TanStack\TanStack;

test('a completed tool turn folds its result into the assistant message that called it', function () {
    $messages = TanStack::toUiMessages([
        new ConversationMessage(['id' => 'msg-1', 'role' => 'user', 'content' => 'Delete draft.txt']),
        new ConversationMessage([
            'id' => 'msg-2',
            'role' => 'assistant',
            'content' => 'Deleting it now.',
            'tool_calls' => [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => ['path' => 'draft.txt']]],
            'tool_results' => [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => ['path' => 'draft.txt'], 'result' => 'Deleted.']],
        ]),
    ]);

    expect($messages)->toEqual([
        ['id' => 'msg-1', 'role' => 'user', 'parts' => [
            ['type' => 'text', 'content' => 'Delete draft.txt'],
        ]],
        ['id' => 'msg-2', 'role' => 'assistant', 'parts' => [
            ['type' => 'text', 'content' => 'Deleting it now.'],
            [
                'type' => 'tool-call',
                'id' => 'call-1',
                'name' => 'DeleteFile',
                'arguments' => '{"path":"draft.txt"}',
                'input' => (object) ['path' => 'draft.txt'],
                'state' => 'complete',
            ],
            ['type' => 'tool-result', 'toolCallId' => 'call-1', 'content' => 'Deleted.', 'state' => 'complete'],
        ]],
    ]);
});

test('a resumed turn anchors its replayed tool result to the message that called it', function () {
    $messages = TanStack::toUiMessages([
        new ConversationMessage([
            'id' => 'msg-1',
            'role' => 'assistant',
            'content' => 'Deleted it.',
            'tool_calls' => [['id' => 'call-2', 'name' => 'ListFiles', 'arguments' => []]],
            'tool_results' => [
                ['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => ['path' => 'draft.txt'], 'result' => 'Deleted.'],
                ['id' => 'call-2', 'name' => 'ListFiles', 'arguments' => [], 'result' => 'a.txt'],
            ],
        ]),
    ]);

    expect($messages)->toHaveCount(1)
        ->and(array_column($messages[0]['parts'], 'type'))->toBe(['text', 'tool-call', 'tool-result'])
        ->and($messages[0]['parts'][2]['toolCallId'])->toBe('call-2');
});

test('client state carries UI messages alongside pending interrupts', function () {
    $state = TanStack::toClientState([
        new ConversationMessage([
            'id' => 'msg-1',
            'role' => 'assistant',
            'tool_calls' => [['id' => 'call-1', 'name' => 'DeleteFile', 'arguments' => ['path' => 'a.txt']]],
            'approval_state' => ['pending' => ['call-1' => 'Deletes a file.']],
        ]),
    ]);

    expect($state['messages'][0]['parts'][0]['name'])->toBe('DeleteFile')
        ->and($state['interrupts'][0]['toolCallId'])->toBe('call-1');
});
