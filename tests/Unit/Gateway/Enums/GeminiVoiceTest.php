<?php

use Laravel\Ai\Gateway\Enums\GeminiVoice;

test('all returns every supported Gemini voice value', function () {
    expect(GeminiVoice::all())->toHaveCount(30)
        ->and(GeminiVoice::all())->toContain('Kore', 'Puck', 'Sulafat');
});

test('default voice helpers return the expected voices', function () {
    expect(GeminiVoice::defaultFemale())->toBe(GeminiVoice::KORE)
        ->and(GeminiVoice::defaultMale())->toBe(GeminiVoice::PUCK);
});

test('random returns a valid Gemini voice case', function () {
    expect(GeminiVoice::cases())->toContain(GeminiVoice::random());
});
