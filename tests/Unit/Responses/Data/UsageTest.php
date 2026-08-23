<?php

use Laravel\Ai\Responses\Data\Usage;

test('usage defaults all tokens to zero', function (): void {
    $usage = new Usage;

    expect($usage->promptTokens)->toBe(0)
        ->and($usage->completionTokens)->toBe(0)
        ->and($usage->cacheWriteInputTokens)->toBe(0)
        ->and($usage->cacheReadInputTokens)->toBe(0)
        ->and($usage->reasoningTokens)->toBe(0)
        ->and($usage->durationSeconds)->toBe(0.0);
});

test('usage accepts the billed audio duration', function (): void {
    $usage = new Usage(100, 50, 25, 10, 5, 3.0);

    expect($usage->durationSeconds)->toBe(3.0)
        ->and($usage->add(new Usage(durationSeconds: 2.0))->durationSeconds)->toBe(5.0)
        ->and($usage->toArray()['duration_seconds'])->toBe(3.0);
});

test('usage accepts token values in constructor', function (): void {
    $usage = new Usage(100, 50, 25, 10, 5);

    expect($usage->promptTokens)->toBe(100)
        ->and($usage->completionTokens)->toBe(50)
        ->and($usage->cacheWriteInputTokens)->toBe(25)
        ->and($usage->cacheReadInputTokens)->toBe(10)
        ->and($usage->reasoningTokens)->toBe(5);
});

test('usage add returns new instance with summed tokens', function (): void {
    $usage1 = new Usage(100, 50, 25, 10, 5);
    $usage2 = new Usage(50, 25, 10, 5, 0);

    $combined = $usage1->add($usage2);

    expect($combined->promptTokens)->toBe(150)
        ->and($combined->completionTokens)->toBe(75)
        ->and($combined->cacheWriteInputTokens)->toBe(35)
        ->and($combined->cacheReadInputTokens)->toBe(15)
        ->and($combined->reasoningTokens)->toBe(5);
});

test('usage add does not mutate original', function (): void {
    $usage1 = new Usage(100, 50, 25, 10, 5);
    $usage2 = new Usage(50, 25, 10, 5, 0);

    $usage1->add($usage2);

    expect($usage1->promptTokens)->toBe(100)
        ->and($usage1->completionTokens)->toBe(50);
});

test('usage to array includes all token fields', function (): void {
    $usage = new Usage(100, 50, 25, 10, 5);

    $array = $usage->toArray();

    expect($array['prompt_tokens'])->toBe(100)
        ->and($array['completion_tokens'])->toBe(50)
        ->and($array['cache_write_input_tokens'])->toBe(25)
        ->and($array['cache_read_input_tokens'])->toBe(10)
        ->and($array['reasoning_tokens'])->toBe(5);
});

test('usage json serialize returns to array', function (): void {
    $usage = new Usage(200, 100, 50, 20, 10);

    $json = $usage->jsonSerialize();

    expect($json['prompt_tokens'])->toBe(200)
        ->and($json['completion_tokens'])->toBe(100);
});
