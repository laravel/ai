<?php

use Laravel\Ai\Ai;
use Tests\Fixtures\Agents\ConversationalAgent;
use Tests\Fixtures\Agents\TrimmingAgent;
use Tests\Fixtures\RecordingStepGateway;

test('a trimming agent still returns its response with middleware active', function () {
    TrimmingAgent::fake(['Trimmed answer.']);

    $response = (new TrimmingAgent)->prompt('Current question');

    expect($response->text)->toBe('Trimmed answer.');
});

test('trimming middleware trims the messages sent to the provider', function () {
    $recorder = new RecordingStepGateway;

    Ai::textProvider('openai')->useTextGateway($recorder);

    (new TrimmingAgent)->prompt('What is my latest question?', provider: 'openai');

    $contents = array_map(fn ($message) => $message->content, $recorder->received);

    expect($recorder->received)->toHaveCount(3)
        ->and($contents)->toBe(['Message two', 'Reply two', 'What is my latest question?'])
        ->and($contents)->not->toContain('Message one');
});

test('trimming middleware trims the messages sent to the provider when streaming', function () {
    $recorder = new RecordingStepGateway;

    Ai::textProvider('openai')->useTextGateway($recorder);

    $response = (new TrimmingAgent)->stream('What is my latest question?', provider: 'openai');

    foreach ($response as $event) {
        // Drain the stream so the step executes.
    }

    $contents = array_map(fn ($message) => $message->content, $recorder->received);

    expect($contents)->toBe(['Message two', 'Reply two', 'What is my latest question?']);
});

test('an agent without trimming middleware sends the full history', function () {
    $recorder = new RecordingStepGateway;

    Ai::textProvider('openai')->useTextGateway($recorder);

    (new ConversationalAgent)->prompt('Hello there', provider: 'openai');

    $contents = array_map(fn ($message) => $message->content, $recorder->received);

    expect($recorder->received)->toHaveCount(2)
        ->and($contents)->toBe(['My name is Taylor Otwell', 'Hello there']);
});
