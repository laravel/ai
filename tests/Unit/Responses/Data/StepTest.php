<?php

use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ProviderToolCall;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;

test('step to array returns all properties including serialized usage and meta', function (): void {
    $usage = new TextUsage(10, 5);
    $meta = new Meta('openai', 'gpt-4o');
    $call = new ProviderToolCall('ws-1', 'web_search_call', ['query' => 'laravel']);
    $step = new Step('test', [], [], FinishReason::Stop, $usage, $meta, 'Thinking.', [['type' => 'thinking', 'signature' => 'sig-1']], [$call]);

    $array = $step->toArray();

    expect($array['text'])->toBe('test')
        ->and($array['tool_calls'])->toBe([])
        ->and($array['tool_results'])->toBe([])
        ->and($array['finish_reason'])->toBe('stop')
        ->and($array['usage'])->toBe($usage)
        ->and($array['meta'])->toBe($meta)
        ->and($array['reasoning'])->toBe('Thinking.')
        ->and($array['replay_blocks'])->toBe([['type' => 'thinking', 'signature' => 'sig-1']])
        ->and($array['provider_tool_calls'])->toBe([$call]);
});

test('step json serialize returns to array', function (): void {
    $usage = new TextUsage(0, 0);
    $meta = new Meta;
    $step = new Step('', [], [], FinishReason::Unknown, $usage, $meta, '', []);

    expect($step->jsonSerialize())->toBe($step->toArray());
});
