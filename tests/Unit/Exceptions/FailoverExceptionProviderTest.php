<?php

use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

test('RateLimitedException exposes provider name as property', function () {
    $e = RateLimitedException::forProvider('anthropic');

    expect($e->provider)->toBe('anthropic');
});

test('ProviderOverloadedException exposes provider name as property', function () {
    $e = ProviderOverloadedException::forProvider('openai');

    expect($e->provider)->toBe('openai');
});

test('InsufficientCreditsException exposes provider name as property', function () {
    $e = InsufficientCreditsException::forProvider('deepseek');

    expect($e->provider)->toBe('deepseek');
});

test('exception message still contains provider name', function () {
    expect(RateLimitedException::forProvider('groq')->getMessage())
        ->toContain('groq');

    expect(ProviderOverloadedException::forProvider('gemini')->getMessage())
        ->toContain('gemini');

    expect(InsufficientCreditsException::forProvider('openrouter')->getMessage())
        ->toContain('openrouter');
});
