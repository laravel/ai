<?php

use Laravel\Ai\Responses\Data\GeneratedImage;

test('generated image stores base64 encoded image', function () {
    $image = new GeneratedImage('SGVsbG8gV29ybGQ=', 'image/png');

    expect($image->image)->toBe('SGVsbG8gV29ybGQ=')
        ->and($image->mime)->toBe('image/png');
});

test('generated image accepts null mime type', function () {
    $image = new GeneratedImage('YmFzZTY0IGRhdGE=');

    expect($image->image)->toBe('YmFzZTY0IGRhdGE=')
        ->and($image->mime)->toBeNull();
});

test('generated image content decodes base64 to string', function () {
    $image = new GeneratedImage('SGVsbG8gV29ybGQ=');

    expect($image->content())->toBe('Hello World');
});

test('generated image to array includes image and mime', function () {
    $image = new GeneratedImage('dGVzdCBpbWFnZQ==', 'image/jpeg');

    $array = $image->toArray();

    expect($array['image'])->toBe('dGVzdCBpbWFnZQ==')
        ->and($array['mime'])->toBe('image/jpeg');
});

test('generated image json serialize returns to array', function () {
    $image = new GeneratedImage('ZGF0YQ==', 'image/webp');

    $json = $image->jsonSerialize();

    expect($json['image'])->toBe('ZGF0YQ==')
        ->and($json['mime'])->toBe('image/webp');
});

test('generated image to string returns decoded content', function () {
    $image = new GeneratedImage('VGVzdA==');

    expect((string) $image)->toBe('Test');
});
