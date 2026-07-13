<?php

use Illuminate\Http\Request;
use Laravel\Ai\Approvals\ToolApproval;
use Laravel\Ai\Vercel\ToolApprovalResponses;

test('tool approval decisions are extracted from a useChat request', function () {
    $approval = ToolApprovalResponses::fromRequest(Request::create('/chat', 'POST', [
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

    expect($approval)->toBeInstanceOf(ToolApproval::class)
        ->and($approval->decisions['call-1']->action)->toBe('approve')
        ->and($approval->decisions['call-2']->action)->toBe('reject')
        ->and($approval->decisions['call-2']->result)->toBe('Keep this file.');
});

test('a denial without a reason becomes a bare rejection', function () {
    $approval = ToolApprovalResponses::fromMessages([
        ['role' => 'assistant', 'parts' => [
            [
                'type' => 'tool-DeleteFile',
                'toolCallId' => 'call-1',
                'state' => 'approval-responded',
                'approval' => ['id' => 'call-1', 'approved' => false],
            ],
        ]],
    ]);

    expect($approval->decisions['call-1']->action)->toBe('reject')
        ->and($approval->decisions['call-1']->result)->toBeNull();
});

test('dynamic tool parts may carry approval responses', function () {
    $approval = ToolApprovalResponses::fromMessages([
        ['role' => 'assistant', 'parts' => [
            [
                'type' => 'dynamic-tool',
                'toolName' => 'DeleteFile',
                'toolCallId' => 'call-1',
                'state' => 'approval-responded',
                'approval' => ['id' => 'call-1', 'approved' => true],
            ],
        ]],
    ]);

    expect($approval->decisions['call-1']->action)->toBe('approve');
});

test('parsing returns null when the request carries no messages', function () {
    expect(ToolApprovalResponses::fromRequest(Request::create('/chat', 'POST', [
        'message' => 'Hello',
    ])))->toBeNull();
});

test('parsing returns null when the trailing message is not from the assistant', function () {
    expect(ToolApprovalResponses::fromMessages([
        ['role' => 'assistant', 'parts' => [
            [
                'type' => 'tool-DeleteFile',
                'toolCallId' => 'call-1',
                'state' => 'approval-responded',
                'approval' => ['id' => 'call-1', 'approved' => true],
            ],
        ]],
        ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'Actually, hold on.']]],
    ]))->toBeNull();
});

test('parsing ignores tool parts that have already been resolved', function () {
    expect(ToolApprovalResponses::fromMessages([
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
    ]))->toBeNull();
});

test('parsing validates malformed approval response parts', function () {
    ToolApprovalResponses::fromMessages([
        ['role' => 'assistant', 'parts' => [
            [
                'type' => 'tool-DeleteFile',
                'toolCallId' => 'call-1',
                'state' => 'approval-responded',
                'approval' => ['id' => 'call-1'],
            ],
        ]],
    ]);
})->throws(InvalidArgumentException::class, 'Tool approval response parts must contain a tool call id and an approval decision.');
