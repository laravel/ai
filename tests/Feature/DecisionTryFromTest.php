<?php

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Decision;

function useChatRequest(array $messages): Request
{
    return Request::create('/chat', 'POST', ['messages' => $messages]);
}

test('tool approval decisions are extracted from a useChat request', function () {
    $approval = Decision::tryFrom(Request::create('/chat', 'POST', [
        'id' => 'chat-1',
        'trigger' => 'submit-message',
        'messages' => [
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Delete a.txt']]],
            ['role' => 'assistant', 'parts' => [
                ['type' => 'step-start'],
                [
                    'type' => 'tool-DeleteFile',
                    'toolCallId' => 'call-1',
                    'state' => 'approval-responded',
                    'input' => ['path' => 'a.txt'],
                    'approval' => ['id' => 'call-1', 'approved' => true],
                ],
                [
                    'type' => 'tool-DeleteFile',
                    'toolCallId' => 'call-2',
                    'state' => 'approval-responded',
                    'input' => ['path' => 'b.txt'],
                    'approval' => ['id' => 'call-2', 'approved' => false, 'reason' => 'Keep this file.'],
                ],
            ]],
        ],
    ]));

    expect($approval)->toBeArray()
        ->and($approval['call-1']->isApproved())->toBeTrue()
        ->and($approval['call-2']->isRejected())->toBeTrue()
        ->and($approval['call-2']->result)->toBe('Keep this file.');
});

test('a denial without a reason becomes a bare rejection', function () {
    $approval = Decision::tryFrom(useChatRequest([
        ['role' => 'assistant', 'parts' => [
            [
                'type' => 'tool-DeleteFile',
                'toolCallId' => 'call-1',
                'state' => 'approval-responded',
                'approval' => ['id' => 'call-1', 'approved' => false],
            ],
        ]],
    ]));

    expect($approval['call-1']->isRejected())->toBeTrue()
        ->and($approval['call-1']->result)->toBeNull();
});

test('dynamic tool parts may carry approval responses', function () {
    $approval = Decision::tryFrom(useChatRequest([
        ['role' => 'assistant', 'parts' => [
            [
                'type' => 'dynamic-tool',
                'toolName' => 'DeleteFile',
                'toolCallId' => 'call-1',
                'state' => 'approval-responded',
                'approval' => ['id' => 'call-1', 'approved' => true],
            ],
        ]],
    ]));

    expect($approval['call-1']->isApproved())->toBeTrue();
});

test('parsing returns null when the request carries no messages', function () {
    expect(Decision::tryFrom(Request::create('/chat', 'POST', [
        'message' => 'Hello',
    ])))->toBeNull();
});

test('parsing returns null when the trailing message is not from the assistant', function () {
    expect(Decision::tryFrom(useChatRequest([
        ['role' => 'assistant', 'parts' => [
            [
                'type' => 'tool-DeleteFile',
                'toolCallId' => 'call-1',
                'state' => 'approval-responded',
                'approval' => ['id' => 'call-1', 'approved' => true],
            ],
        ]],
        ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Actually, hold on.']]],
    ])))->toBeNull();
});

test('parsing ignores tool parts that have already been resolved', function () {
    expect(Decision::tryFrom(useChatRequest([
        ['role' => 'assistant', 'parts' => [
            [
                'type' => 'tool-DeleteFile',
                'toolCallId' => 'call-1',
                'state' => 'output-available',
                'output' => 'deleted',
                'approval' => ['id' => 'call-1', 'approved' => true],
            ],
            [
                'type' => 'tool-DeleteFile',
                'toolCallId' => 'call-2',
                'state' => 'output-denied',
                'approval' => ['id' => 'call-2', 'approved' => false],
            ],
        ]],
    ])))->toBeNull();
});

test('a request with malformed approval parts fails validation instead of crashing', function () {
    Decision::tryFrom(useChatRequest([
        ['role' => 'assistant', 'parts' => [
            [
                'type' => 'tool-DeleteFile',
                'toolCallId' => 'call-1',
                'state' => 'approval-responded',
                'approval' => ['id' => 'call-1'],
            ],
        ]],
    ]));
})->throws(ValidationException::class);

test('a client may not smuggle the wildcard tool call id to approve every pending call', function () {
    Decision::tryFrom(useChatRequest([
        ['role' => 'assistant', 'parts' => [
            [
                'type' => 'tool-DeleteFile',
                'toolCallId' => '*',
                'state' => 'approval-responded',
                'approval' => ['id' => '*', 'approved' => true],
            ],
        ]],
    ]));
})->throws(ValidationException::class);

test('conflicting approval responses for the same tool call fail validation instead of last-winning', function () {
    Decision::tryFrom(useChatRequest([
        ['role' => 'assistant', 'parts' => [
            [
                'type' => 'tool-DeleteFile',
                'toolCallId' => 'call-1',
                'state' => 'approval-responded',
                'approval' => ['id' => 'call-1', 'approved' => true],
            ],
            [
                'type' => 'tool-DeleteFile',
                'toolCallId' => 'call-1',
                'state' => 'approval-responded',
                'approval' => ['id' => 'call-1', 'approved' => false],
            ],
        ]],
    ]));
})->throws(ValidationException::class);
