<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Ai\Approvals\ApprovalSignature;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Tests\Fixtures\Agents\StatelessApprovableAgent;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;

function resumeInterrupt(string $interruptId, array $arguments = []): void
{
    $chat = AgentUserInteraction::chat([
        'threadId' => 'thread-1',
        'runId' => 'run-2',
        'messages' => [
            ['id' => 'm1', 'role' => 'user', 'content' => 'Generate a number'],
            ['id' => 'm2', 'role' => 'assistant', 'toolCalls' => [[
                'id' => 'toolu_1',
                'type' => 'function',
                'function' => ['name' => 'ApprovableNumberGenerator', 'arguments' => json_encode((object) $arguments)],
            ]]],
        ],
        'resume' => [['interruptId' => $interruptId, 'status' => 'resolved', 'payload' => ['approved' => true]]],
    ]);

    (new StatelessApprovableAgent)->withMessages($chat->history())->prompt($chat, provider: 'anthropic');
}

beforeEach(function (): void {
    ApprovableNumberGenerator::$invocations = 0;

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_2',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'Done.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);
});

test('a client cannot run a tool the model never called', function (): void {
    expect(fn () => resumeInterrupt('toolu_1'))->toThrow(ApprovalMismatchException::class);

    expect(ApprovableNumberGenerator::$invocations)->toBe(0);
});

test('a client cannot change the arguments of an interrupt the server issued', function (): void {
    $id = 'toolu_1.'.ApprovalSignature::sign('toolu_1', 'ApprovableNumberGenerator', []);

    expect(fn () => resumeInterrupt($id, ['amount' => 9999]))->toThrow(ApprovalMismatchException::class);

    expect(ApprovableNumberGenerator::$invocations)->toBe(0);
});

test('an interrupt the server issued still resumes', function (): void {
    resumeInterrupt('toolu_1.'.ApprovalSignature::sign('toolu_1', 'ApprovableNumberGenerator', []));

    expect(ApprovableNumberGenerator::$invocations)->toBe(1);
});
