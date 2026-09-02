<?php

use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\Usage;

test('step stores text tool calls and other properties', function (): void {
    $usage = new Usage(10, 5);
    $meta = new Meta('openai', 'gpt-4o');
    $step = new Step(
        text: 'Hello',
        toolCalls: [],
        toolResults: [],
        finishReason: FinishReason::Stop,
        usage: $usage,
        meta: $meta,
        providerContentBlocks: [['type' => 'reasoning', 'summary' => [['text' => 'Checked constraints.']]]],
    );

    expect($step->text)->toBe('Hello')
        ->and($step->toolCalls)->toBeEmpty()
        ->and($step->toolResults)->toBeEmpty()
        ->and($step->finishReason)->toBe(FinishReason::Stop)
        ->and($step->usage)->toBe($usage)
        ->and($step->meta)->toBe($meta)
        ->and($step->providerContentBlocks)->toBe([['type' => 'reasoning', 'summary' => [['text' => 'Checked constraints.']]]]);
});

test('step to array returns all properties including serialized usage and meta', function (): void {
    $usage = new Usage(10, 5);
    $meta = new Meta('openai', 'gpt-4o');
    $step = new Step('test', [], [], FinishReason::Stop, $usage, $meta);

    $array = $step->toArray();

    expect($array['text'])->toBe('test')
        ->and($array['tool_calls'])->toBe([])
        ->and($array['tool_results'])->toBe([])
        ->and($array['finish_reason'])->toBe('stop')
        ->and($array['usage'])->toBe($usage)
        ->and($array['meta'])->toBe($meta)
        ->and($array['provider_content_blocks'])->toBe([]);
});

test('step json serialize returns to array', function (): void {
    $usage = new Usage(0, 0);
    $meta = new Meta;
    $step = new Step('', [], [], FinishReason::Unknown, $usage, $meta);

    expect($step->jsonSerialize())->toBe($step->toArray());
});
