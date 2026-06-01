<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

test('toHtml renders an img tag with the image mime type', function () {
    $response = new ImageResponse(
        new Collection([new GeneratedImage('aGVsbG8=', 'image/jpeg')]),
        new Usage,
        new Meta('openai', 'gpt-image-1'),
    );

    expect($response->toHtml())->toBe(
        '<img src="data:image/jpeg;base64,aGVsbG8=" alt="" />'
    );
});

test('toHtml falls back to image/png when the image has no mime type', function () {
    $response = new ImageResponse(
        new Collection([new GeneratedImage('aGVsbG8=')]),
        new Usage,
        new Meta('openai', 'gpt-image-1'),
    );

    expect($response->toHtml())->toBe(
        '<img src="data:image/png;base64,aGVsbG8=" alt="" />'
    );
});

test('toHtml escapes the alt attribute', function () {
    $response = new ImageResponse(
        new Collection([new GeneratedImage('aGVsbG8=', 'image/png')]),
        new Usage,
        new Meta('openai', 'gpt-image-1'),
    );

    expect($response->toHtml('"><script>alert(1)</script>'))->toBe(
        '<img src="data:image/png;base64,aGVsbG8=" alt="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;" />'
    );
});
