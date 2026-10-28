<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Tests\Fixtures\Responses\SubclassedStep;
use Tests\Fixtures\Responses\SubclassedTextResponse;

test('serialization preserves private properties declared on text response subclasses', function (): void {
    $response = new SubclassedTextResponse('Hello', new TextUsage(1, 2), new Meta('anthropic', 'claude'));
    $response->rememberSecret('changed');
    $response->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

    $restored = unserialize(serialize($response));

    expect($restored->secret())->toBe('changed')
        ->and($restored->text)->toBe('Hello')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves private properties declared on step subclasses', function (): void {
    $step = new SubclassedStep('Hello', [], [], FinishReason::Stop, new TextUsage(1, 2), new Meta('anthropic', 'claude'), '', []);
    $step->rememberSecret('changed');
    $step->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

    $restored = unserialize(serialize($step));

    expect($restored->secret())->toBe('changed')
        ->and($restored->text)->toBe('Hello')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves classification answers and drops the raw response', function (): void {
    $response = (new ClassificationResponse(['is_urgent' => new BooleanAnswer(0.9)], new TextUsage(1, 2), new Meta('openai', 'gpt-6-luna')))
        ->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

    $restored = unserialize(serialize($response));

    expect($restored->answer('is_urgent')->probability)->toBe(0.9)
        ->and($restored->usage->outputTokens)->toBe(2)
        ->and($restored->meta->model)->toBe('gpt-6-luna')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves embeddings and drops the raw response', function (): void {
    $response = (new EmbeddingsResponse([[0.1, 0.2], [0.3, 0.4]], new Usage(10), new Meta('openai', 'text-embedding-3-small')))
        ->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

    $restored = unserialize(serialize($response));

    expect($restored->embeddings)->toBe([[0.1, 0.2], [0.3, 0.4]])
        ->and($restored->usage->inputTokens)->toBe(10)
        ->and($restored->meta->provider)->toBe('openai')
        ->and($restored->meta->model)->toBe('text-embedding-3-small')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves transcription data and drops the raw response', function (): void {
    $response = (new TranscriptionResponse(
        'Hello, world!',
        collect([new TranscriptionSegment('Hello, world!', 'Speaker 1', 1.5, 3.25)]),
        new TranscriptionUsage(10, 4, audioSeconds: 3.25),
        new Meta('openai', 'gpt-4o-transcribe'),
    ))->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

    $restored = unserialize(serialize($response));

    expect($restored->text)->toBe('Hello, world!')
        ->and($restored->segments->toArray())->toBe([
            ['text' => 'Hello, world!', 'speaker' => 'Speaker 1', 'start_seconds' => 1.5, 'end_seconds' => 3.25],
        ])
        ->and($restored->usage->inputTokens)->toBe(10)
        ->and($restored->usage->outputTokens)->toBe(4)
        ->and($restored->usage->audioSeconds)->toBe(3.25)
        ->and($restored->meta->provider)->toBe('openai')
        ->and($restored->meta->model)->toBe('gpt-4o-transcribe')
        ->and($restored->raw)->toBeNull();
});

test('serialization preserves image data and drops the raw response', function (): void {
    $response = (new ImageResponse(
        collect([new GeneratedImage(base64_encode('image-content'), 'image/webp')]),
        new ImageUsage(41, 1024, imageOutputTokens: 1000),
        new Meta('openai', 'gpt-image-1'),
    ))->withRawResponse(new Response(new Psr7Response(200, [], '{}')));

    $restored = unserialize(serialize($response));

    expect($restored->images)->toHaveCount(1)
        ->and($restored->firstImage()->content())->toBe('image-content')
        ->and($restored->firstImage()->mime())->toBe('image/webp')
        ->and($restored->usage->inputTokens)->toBe(41)
        ->and($restored->usage->outputTokens)->toBe(1024)
        ->and($restored->usage->imageOutputTokens)->toBe(1000)
        ->and($restored->meta->provider)->toBe('openai')
        ->and($restored->meta->model)->toBe('gpt-image-1')
        ->and($restored->raw)->toBeNull();
});
