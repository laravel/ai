<?php

use Laravel\Ai\Responses\Data\Meta;

test('meta extracts provider and model from response', function () {
    $meta = new Meta('openai', 'gpt-4o');

    expect($meta->provider)->toBe('openai')
        ->and($meta->model)->toBe('gpt-4o');
});

test('meta accepts null provider and model', function () {
    $meta = new Meta(null, null);

    expect($meta->provider)->toBeNull()
        ->and($meta->model)->toBeNull();
});

test('meta returns empty citations when not provided', function () {
    $meta = new Meta('openai', 'gpt-4o');

    expect($meta->citations)->toBeEmpty();
});

test('meta to array includes provider model and citations', function () {
    $meta = new Meta('openai', 'gpt-4o');

    $array = $meta->toArray();

    expect($array['provider'])->toBe('openai')
        ->and($array['model'])->toBe('gpt-4o')
        ->and($array['citations'])->toBe([]);
});

test('meta json serialize returns to array', function () {
    $meta = new Meta('anthropic', 'claude-3-opus');

    $json = $meta->jsonSerialize();

    expect($json['provider'])->toBe('anthropic')
        ->and($json['model'])->toBe('claude-3-opus');
});

test('meta defaults response id to null', function () {
    $meta = new Meta('openai', 'gpt-4o');

    expect($meta->responseId)->toBeNull();
});

test('meta stores response id passed as the last argument', function () {
    $meta = new Meta('openai', 'gpt-4o', null, 'resp_123');

    expect($meta->responseId)->toBe('resp_123');
});

test('meta to array includes response id', function () {
    $meta = new Meta('openai', 'gpt-4o', null, 'resp_123');

    $array = $meta->toArray();

    expect($array)->toHaveKey('response_id')
        ->and($array['response_id'])->toBe('resp_123');
});

test('meta to array response id is null when not provided', function () {
    $meta = new Meta('openai', 'gpt-4o');

    expect($meta->toArray()['response_id'])->toBeNull();
});

test('meta json serialize includes response id', function () {
    $meta = new Meta('openai', 'gpt-4o', null, 'resp_123');

    expect($meta->jsonSerialize()['response_id'])->toBe('resp_123');
});
