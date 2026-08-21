<?php

use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Streaming\Protocols\VercelDataProtocol;

function vercelProtocolParts(array|Closure $events, ?string $messageId = null): array
{
    $stream = $events instanceof Closure ? $events : fn () => yield from $events;

    $response = (new StreamableAgentResponse('invocation-1', $stream, new Data\Meta('anthropic', 'claude-sonnet-4-6')))
        ->usingProtocol(new VercelDataProtocol($messageId))
        ->toResponse(request());

    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    $response->sendContent();

    ob_end_clean();

    return collect(explode("\n\n", trim($output)))
        ->map(fn (string $frame) => str_replace('data: ', '', $frame))
        ->map(fn (string $payload) => $payload === '[DONE]' ? ['type' => 'done'] : json_decode($payload, true))
        ->all();
}

function vercelFinishPart(string $reason = 'stop', ?Usage $usage = null): array
{
    $usage ??= new Usage;

    return [
        'type' => 'finish',
        'finishReason' => $reason,
        'messageMetadata' => [
            'usage' => [
                'inputTokens' => $usage->promptTokens,
                'outputTokens' => $usage->completionTokens,
                'totalTokens' => $usage->promptTokens + $usage->completionTokens,
                'reasoningTokens' => $usage->reasoningTokens,
                'cachedInputTokens' => $usage->cacheReadInputTokens,
            ],
        ],
    ];
}

test('a text stream emits start, delta, and end parts for the message', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'msg-1', time()),
        new TextDelta('event-2', 'msg-1', 'Hello.', time()),
        new TextEnd('event-3', 'msg-1', time()),
        new StreamEnd('event-4', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'text-start', 'id' => 'msg-1'],
        ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Hello.'],
        ['type' => 'text-end', 'id' => 'msg-1'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a reasoning stream emits start, delta, and end parts for the reasoning block', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ReasoningStart('event-1', 'reasoning-1', time()),
        new ReasoningDelta('event-2', 'reasoning-1', 'Considering the options.', time()),
        new ReasoningEnd('event-3', 'reasoning-1', time()),
        new StreamEnd('event-4', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'reasoning-start', 'id' => 'reasoning-1'],
        ['type' => 'reasoning-delta', 'id' => 'reasoning-1', 'delta' => 'Considering the options.'],
        ['type' => 'reasoning-end', 'id' => 'reasoning-1'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a cited text stream emits a source url part for the cited page', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'msg-1', time()),
        new TextDelta('event-2', 'msg-1', 'Laravel is a PHP framework.', time()),
        new Citation('event-3', 'msg-1', new Data\UrlCitation('https://laravel.com/docs', 'Laravel Documentation'), time()),
        new TextEnd('event-4', 'msg-1', time()),
        new StreamEnd('event-5', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'text-start', 'id' => 'msg-1'],
        ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Laravel is a PHP framework.'],
        ['type' => 'source-url', 'sourceId' => 'https://laravel.com/docs', 'url' => 'https://laravel.com/docs', 'title' => 'Laravel Documentation'],
        ['type' => 'text-end', 'id' => 'msg-1'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a url citation without a title omits only the title from the source url part', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Citation('event-1', 'msg-1', new Data\UrlCitation('https://laravel.com/docs'), time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect($parts[2])->toBe([
        'type' => 'source-url',
        'sourceId' => 'https://laravel.com/docs',
        'url' => 'https://laravel.com/docs',
    ]);
});

test('an unknown citation type is skipped instead of ending the stream', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Citation('event-1', 'msg-1', new class extends Data\Citation {}, time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a failed stream emits an error part instead of a finish part', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextStart('event-1', 'msg-1', time()),
        new Error('event-2', 'overloaded_error', 'Overloaded', false, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'text-start', 'id' => 'msg-1'],
        ['type' => 'error', 'errorText' => 'Overloaded'],
        ['type' => 'done'],
    ]);
});

test('a paused stream emits an approval request part for each pending approval', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
        new ToolApprovalRequest('event-2', collect([
            new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Destructive operation.'),
        ]), time()),
        new StreamEnd('event-3', 'tool_calls', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'DeleteFile', 'input' => ['path' => 'a.txt']],
        ['type' => 'tool-approval-request', 'toolCallId' => 'call-1', 'approvalId' => 'call-1', 'reason' => 'Destructive operation.'],
        ['type' => 'finish-step'],
        vercelFinishPart('tool-calls'),
        ['type' => 'done'],
    ]);
});

test('a resumed stream emits the approved tool output for the prior turn tool call', function () {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-2', 'msg-2', 'Done.', time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ], messageId: 'client-message-1');

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'client-message-1'],
        ['type' => 'start-step'],
        ['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'deleted'],
        ['type' => 'finish-step'],
        ['type' => 'start-step'],
        ['type' => 'text-delta', 'id' => 'msg-2', 'delta' => 'Done.'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a resumed stream may continue an existing client-side message', function () {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ], messageId: 'client-message-1');

    expect($parts[0])->toBe(['type' => 'start', 'messageId' => 'client-message-1'])
        ->and(collect($parts)->where('type', 'start'))->toHaveCount(1);
});

test('a rejected approval streams as a denied tool output', function () {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The user rejected this tool call.'), false, 'The user rejected this tool call.', time(), denied: true),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ], messageId: 'client-message-1');

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'client-message-1'],
        ['type' => 'start-step'],
        ['type' => 'tool-output-denied', 'toolCallId' => 'call-1'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});

test('a tool result without a prior call or existing message is skipped', function () {
    $parts = vercelProtocolParts([
        new ToolResult('event-1', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'done'],
    ]);
});

test('an unexecuted tool call streams as a tool output error', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The agent reached its maximum number of steps without running this tool call.'), false, 'The agent reached its maximum number of steps without running this tool call.', time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect($parts[3])->toBe([
        'type' => 'tool-output-error',
        'toolCallId' => 'call-1',
        'errorText' => 'The agent reached its maximum number of steps without running this tool call.',
    ]);
});

test('a failed tool call without an error message streams a default error text', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], null), false, null, time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect($parts[3])->toBe([
        'type' => 'tool-output-error',
        'toolCallId' => 'call-1',
        'errorText' => 'The tool call failed.',
    ]);
});

test('the finish part carries the stream usage and finish reason as message metadata', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-1', 'msg-1', 'Hello.', time()),
        new StreamEnd('event-2', 'length', new Usage(promptTokens: 10, completionTokens: 20), time()),
    ]);

    expect($parts[count($parts) - 2])->toBe(vercelFinishPart('length', new Usage(promptTokens: 10, completionTokens: 20)));
});

test('finish reasons outside the Vercel enum emit as other', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'continue', new Usage, time()),
    ]);

    expect($parts[count($parts) - 2])->toBe(vercelFinishPart('other'));
});

test('an unknown finish reason emits as other', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new StreamEnd('event-1', 'unknown', new Usage, time()),
    ]);

    expect($parts[count($parts) - 2])->toBe(vercelFinishPart('other'));
});

test('a provider tool event emits as a custom provider part', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'openai', 'gpt-5', time()),
        new ProviderToolEvent('event-1', 'search-1', 'web_search_call', ['query' => 'Laravel'], 'in_progress', time(), 'openai'),
        new StreamEnd('event-2', 'stop', new Usage, time()),
    ]);

    expect($parts[2])->toBe([
        'type' => 'custom',
        'kind' => 'openai.web_search_call',
        'providerMetadata' => [
            'openai' => [
                'itemId' => 'search-1',
                'status' => 'in_progress',
                'data' => ['query' => 'Laravel'],
            ],
        ],
    ]);
});

test('a multi-step stream emits one finish part with combined usage and the final reason', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new StreamEnd('event-2', 'tool_calls', new Usage(promptTokens: 10, completionTokens: 5), time()),
        new ToolResult('event-3', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new TextDelta('event-4', 'msg-1', 'Sunny.', time()),
        new StreamEnd('event-5', 'stop', new Usage(promptTokens: 20, completionTokens: 15), time()),
    ]);

    expect(collect($parts)->where('type', 'finish')->values()->all())->toBe([
        vercelFinishPart('stop', new Usage(promptTokens: 30, completionTokens: 20)),
    ])->and(collect($parts)->pluck('type')->all())->toBe([
        'start', 'start-step',
        'tool-input-available', 'tool-output-available', 'finish-step',
        'start-step', 'text-delta', 'finish-step',
        'finish', 'done',
    ]);
});

test('an exception mid-stream is reported and emitted as a masked terminal error part', function () {
    Exceptions::fake();

    $parts = vercelProtocolParts(function () {
        yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
        yield new TextDelta('event-1', 'msg-1', 'Hel', time());

        throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
    });

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Hel'],
        ['type' => 'error', 'errorText' => 'An error occurred.'],
        ['type' => 'done'],
    ]);

    Exceptions::assertReported(RuntimeException::class);
});

test('a provider stream error followed by the loop exception emits a single error part', function () {
    Exceptions::fake();

    $parts = vercelProtocolParts(function () {
        yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
        yield new TextDelta('event-1', 'msg-1', 'Hel', time());

        $error = new Error('event-2', 'overloaded_error', 'Overloaded', false, time());

        yield $error;

        throw new StreamErrorException($error);
    });

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'text-delta', 'id' => 'msg-1', 'delta' => 'Hel'],
        ['type' => 'error', 'errorText' => 'Overloaded'],
        ['type' => 'done'],
    ]);

    Exceptions::assertNothingReported();
});

test('an unexpected exception after an error part is still reported', function () {
    Exceptions::fake();

    $parts = vercelProtocolParts(function () {
        yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
        yield new Error('event-1', 'overloaded_error', 'Overloaded', false, time());

        throw new RuntimeException('Broken pipe');
    });

    expect(collect($parts)->where('type', 'error')->count())->toBe(1);

    Exceptions::assertReported(RuntimeException::class);
});

test('a stream end after an error does not emit finish parts', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new Error('event-1', 'overloaded_error', 'Overloaded', false, time()),
        new StreamEnd('event-2', 'error', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'error', 'errorText' => 'Overloaded'],
        ['type' => 'done'],
    ]);
});

test('a tool executed within the stream emits its input and output parts', function () {
    $parts = vercelProtocolParts([
        new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
        new ToolCall('event-1', new Data\ToolCall('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
        new ToolResult('event-2', new Data\ToolResult('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
        new StreamEnd('event-3', 'stop', new Usage, time()),
    ]);

    expect($parts)->toBe([
        ['type' => 'start', 'messageId' => 'msg-1'],
        ['type' => 'start-step'],
        ['type' => 'tool-input-available', 'toolCallId' => 'call-1', 'toolName' => 'GetWeather', 'input' => ['city' => 'Lisbon']],
        ['type' => 'tool-output-available', 'toolCallId' => 'call-1', 'output' => 'sunny'],
        ['type' => 'finish-step'],
        vercelFinishPart(),
        ['type' => 'done'],
    ]);
});
