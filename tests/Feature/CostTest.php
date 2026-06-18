<?php

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\Fixtures\Agents\AssistantAgent;

test('response exposes its dollar cost from usage and model pricing', function () {
    config(['ai.pricing.models' => [
        'openai' => ['cost-test-model' => ['input' => 2.0, 'output' => 8.0]],
    ]]);

    AssistantAgent::fake(fn () => new TextResponse(
        'Hello',
        new Usage(promptTokens: 1_000_000, completionTokens: 500_000),
        new Meta('openai', 'cost-test-model'),
    ));

    $cost = (new AssistantAgent)->prompt('Hi')->cost();

    expect($cost->input)->toBe(2.0)
        ->and($cost->output)->toBe(4.0)
        ->and($cost->total())->toBe(6.0)
        ->and($cost->isKnown())->toBeTrue()
        ->and($cost->format(2))->toBe('$6.00');
});

test('response cost is unknown when the model has no pricing', function () {
    AssistantAgent::fake(fn () => new TextResponse(
        'Hello',
        new Usage(promptTokens: 1_000),
        new Meta('openai', 'totally-unknown-model'),
    ));

    $cost = (new AssistantAgent)->prompt('Hi')->cost();

    expect($cost->isKnown())->toBeFalse()
        ->and($cost->total())->toBe(0.0);
});
