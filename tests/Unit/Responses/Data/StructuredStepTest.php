<?php

use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\StructuredStep;
use Laravel\Ai\Responses\Data\Usage;

test('structured step stores structured data', function (): void {
    $step = new StructuredStep(
        'test response',
        ['key' => 'value'],
        [],
        [],
        FinishReason::Stop,
        new Usage,
        new Meta('openai', 'gpt-4o')
    );

    expect($step->text)->toBe('test response')
        ->and($step->structured)->toBe(['key' => 'value']);
});

test('structured step to array includes structured data', function (): void {
    $step = new StructuredStep(
        'text content',
        ['name' => 'test', 'count' => 5],
        [],
        [],
        FinishReason::Stop,
        new Usage,
        new Meta('anthropic', 'claude-3')
    );

    $array = $step->toArray();

    expect($array['text'])->toBe('text content')
        ->and($array['structured'])->toBe(['name' => 'test', 'count' => 5]);
});

test('structured step jsonSerialize includes structured data', function (): void {
    $step = new StructuredStep(
        'response text',
        ['result' => true],
        [],
        [],
        FinishReason::Stop,
        new Usage,
        new Meta('openai', 'gpt-4o')
    );

    $json = json_decode(json_encode($step), true);

    expect($json['structured'])->toBe(['result' => true])
        ->and($json['text'])->toBe('response text');
});
