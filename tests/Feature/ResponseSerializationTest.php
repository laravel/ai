<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
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
