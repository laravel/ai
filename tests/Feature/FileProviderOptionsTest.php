<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Document;

test('array provider options resolve for the given provider', function () {
    $document = Document::fromString('Hello')->withProviderOptions(['purpose' => 'fine-tune']);

    expect($document->providerOptions(Lab::OpenAI))->toBe(['purpose' => 'fine-tune']);
});

test('closure provider options resolve per provider', function () {
    $document = Document::fromString('Hello')->withProviderOptions(fn (Lab $provider) => match ($provider) {
        Lab::OpenAI => ['purpose' => 'assistants'],
        default => [],
    });

    expect($document->providerOptions(Lab::OpenAI))->toBe(['purpose' => 'assistants'])
        ->and($document->providerOptions(Lab::Anthropic))->toBe([]);
});

test('closure provider options survive php serialization', function () {
    $document = Document::fromString('Hello')->withProviderOptions(fn (Lab $provider) => match ($provider) {
        Lab::OpenAI => ['purpose' => 'assistants'],
        default => [],
    });

    $restored = unserialize(serialize($document));

    expect($restored->providerOptions(Lab::OpenAI))->toBe(['purpose' => 'assistants']);
});
