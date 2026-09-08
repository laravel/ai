<?php

use Laravel\Ai\Harness\ClaudeCode\StreamJsonParser;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Harness\PermissionMode;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

it('maps partial text thinking host calls and failures without replaying complete messages', function () {
    $run = new HarnessRun('Agent', '', '', 'sonnet', 'session', '/', PermissionMode::Default, ['WriteNote' => 'WriteNote'], 10, 'run');
    $parser = new StreamJsonParser($run);
    $events = [];

    foreach (file(__DIR__.'/../../Fixtures/claude/partial.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
        array_push($events, ...$parser->parse($line));
    }

    expect(TextDelta::combine($events))->toBe('Hello world')
        ->and(collect($events)->whereInstanceOf(ReasoningDelta::class)->pluck('delta')->implode(''))->toBe('Let me think.')
        ->and(collect($events)->whereInstanceOf(ToolCall::class)->first()->toolCall->name)->toBe('WriteNote')
        ->and(collect($events)->whereInstanceOf(ToolResult::class)->first()->successful)->toBeFalse()
        ->and($parser->result->text)->toBe('Hello world')
        ->and($parser->result->usage->toArray())->toBe([
            'prompt_tokens' => 20, 'completion_tokens' => 8, 'cache_write_input_tokens' => 5,
            'cache_read_input_tokens' => 3, 'reasoning_tokens' => 0,
        ]);
});

it('maps complete messages when partial messages are absent and ignores subagent text', function () {
    $parser = new StreamJsonParser(new HarnessRun('Agent', '', '', 'sonnet', 'session', '/', PermissionMode::BypassPermissions, [], 10, 'run'));
    $message = ['type' => 'assistant', 'message' => ['id' => 'full', 'content' => [['type' => 'thinking', 'thinking' => 'Thinking'], ['type' => 'text', 'text' => 'Answer']]]];
    $events = $parser->parse(json_encode($message));
    expect(TextDelta::combine($events))->toBe('Answer')
        ->and(collect($events)->whereInstanceOf(ReasoningDelta::class)->first()->delta)->toBe('Thinking')
        ->and($parser->parse(json_encode([...$message, 'parent_tool_use_id' => 'parent'])))->toBe([]);
});

it('parses captured CLI output with assistant messages interleaved before block stop', function () {
    $parser = new StreamJsonParser(new HarnessRun('Agent', '', '', 'sonnet', 'session', '/', PermissionMode::BypassPermissions, [], 10, 'run'));
    $events = [];
    foreach (file(__DIR__.'/../../Fixtures/claude/captured.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
        array_push($events, ...$parser->parse($line));
    }

    expect(TextDelta::combine($events))->toBe('CLI verification complete.')
        ->and(collect($events)->map(fn ($event) => $event->type())->all())->toBe(['stream_start', 'text_start', 'text_delta', 'text_end'])
        ->and($parser->result->sessionId)->toBe('11111111-1111-4111-8111-111111111111');
});

it('maps native tool events captured from the installed CLI', function () {
    $parser = new StreamJsonParser(new HarnessRun('Agent', '', '', 'sonnet', 'session', '/', PermissionMode::BypassPermissions, [], 10, 'run'));
    $events = [];
    foreach (file(__DIR__.'/../../Fixtures/claude/captured-tools.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
        array_push($events, ...$parser->parse($line));
    }
    $call = collect($events)->whereInstanceOf(ToolCall::class)->first()->toolCall;
    $result = collect($events)->whereInstanceOf(ToolResult::class)->first()->toolResult;
    expect($call->name)->toBe('Read')->and($result->id)->toBe($call->id)
        ->and($result->result)->toContain('A harmless test note.')
        ->and(TextDelta::combine($events))->toBe('Tool verification complete.');
});
