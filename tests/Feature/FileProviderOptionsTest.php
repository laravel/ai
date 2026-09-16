<?php

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Document;

test('array provider options resolve for the given provider', function (): void {
    $document = Document::fromString('Hello')->withProviderOptions(['purpose' => 'fine-tune']);

    expect($document->providerOptions(Lab::OpenAI))->toBe(['purpose' => 'fine-tune']);
});

test('closure provider options resolve per provider', function (): void {
    $document = Document::fromString('Hello')->withProviderOptions(fn (Lab $provider): array => match ($provider) {
        Lab::OpenAI => ['purpose' => 'assistants'],
        default => [],
    });

    expect($document->providerOptions(Lab::OpenAI))->toBe(['purpose' => 'assistants'])
        ->and($document->providerOptions(Lab::Anthropic))->toBe([]);
});

test('closure provider options survive php serialization', function (): void {
    $document = Document::fromString('Hello')->withProviderOptions(fn (Lab $provider): array => match ($provider) {
        Lab::OpenAI => ['purpose' => 'assistants'],
        default => [],
    });

    $restored = unserialize(serialize($document));

    expect($restored->providerOptions(Lab::OpenAI))->toBe(['purpose' => 'assistants']);
});

test('headers compose with provider options', function (): void {
    $document = Document::fromString('Hello')
        ->withHeaders(fn (Lab $provider): array => ['X-Provider' => $provider->value])
        ->withProviderOptions(['purpose' => 'fine-tune']);

    expect($document->providerOptions(Lab::OpenAI))->toBe([
        'purpose' => 'fine-tune',
        'ai_sdk_extra_headers' => ['X-Provider' => 'openai'],
    ]);
});
