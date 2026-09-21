<?php

use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;

test('decision can be made from stringable macro', function (): void {
    Classification::fake(fn (ClassificationPrompt $prompt) => [
        'decision' => new BooleanAnswer($prompt->contains('FREE MONEY') ? 0.95 : 0.05),
    ]);

    expect(Str::of('FREE MONEY NOW')->decide('Is this spam?'))->toBeTrue()
        ->and(str('Lunch at noon?')->decide('Is this spam?'))->toBeFalse();

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('decision')
        && $prompt->contains('FREE MONEY'));
});

test('decision honors the given threshold', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.7)]]);

    expect(str('Maybe spam.')->decide('Is this spam?', threshold: 0.9))->toBeFalse();
});

test('decision macro passes through criteria and options', function (): void {
    Classification::fake();

    Str::decide(
        'Some text.',
        'Is this spam?',
        criteria: ['true' => 'Unsolicited bulk mail.'],
        provider: Lab::TypeSafe,
        model: 'custom-model',
        timeout: 45,
    );

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('Some text.')
        && $prompt->model === 'custom-model'
        && $prompt->timeout === 45
        && $prompt->questions['decision']->criteria === ['true' => 'Unsolicited bulk mail.']);
});
