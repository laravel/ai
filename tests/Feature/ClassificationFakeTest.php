<?php

use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Category;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\CategoryAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;

test('classify rejects blank state', function (): void {
    Classification::fake();

    Classification::of('  ');
})->throws(InvalidArgumentException::class, 'A non-blank state is required to classify.');

test('classify rejects missing questions', function (): void {
    Classification::fake();

    Classification::of('text')->classify();
})->throws(InvalidArgumentException::class, 'At least one question is required to classify.');

test('classify rejects questions without string keys', function (): void {
    Classification::fake();

    Classification::of('text')->questions([new Boolean('Urgent?')]);
})->throws(InvalidArgumentException::class, 'Questions must be Question instances keyed by a string.');

test('category requires at least two options', function (): void {
    new Category('Team?', ['billing' => null]);
})->throws(InvalidArgumentException::class, 'A category question requires at least two options.');

test('score requires a list of at least two levels', function (): void {
    new Score('How mad?', ['low' => 'Calm', 'high' => 'Angry']);
})->throws(InvalidArgumentException::class, 'A score question requires a list of at least two levels.');

test('fake generates shape-valid answers for every question', function (): void {
    Classification::fake();

    $response = Classification::of('text')->questions([
        'is_urgent' => new Boolean('Urgent?'),
        'department' => new Category('Team?', ['billing' => null, 'technical' => null]),
        'frustration' => new Score('How mad?', ['Calm', 'Annoyed', 'Angry']),
    ])->classify();

    expect($response['is_urgent'])->toBeInstanceOf(BooleanAnswer::class)
        ->and($response['is_urgent']->probability)->toBeBetween(0.0, 1.0)
        ->and($response['department'])->toBeInstanceOf(CategoryAnswer::class)
        ->and($response['department']->category)->toBeIn(['billing', 'technical'])
        ->and(array_keys($response['department']->probabilities))->toBe(['billing', 'technical'])
        ->and(round(array_sum($response['department']->probabilities), 2))->toBe(1.0)
        ->and($response['frustration'])->toBeInstanceOf(ScoreAnswer::class)
        ->and($response['frustration']->score)->toBeBetween(0.0, 2.0)
        ->and($response['frustration']->legend)->toBe(['Calm', 'Annoyed', 'Angry'])
        ->and($response->meta->provider)->toBe('typesafe');
});

test('fake answers may be overridden per question', function (): void {
    Classification::fake([
        ['department' => new CategoryAnswer('billing', ['billing' => 0.9, 'technical' => 0.1], 0.9)],
    ]);

    $response = Classification::of('text')->questions([
        'is_urgent' => new Boolean('Urgent?'),
        'department' => new Category('Team?', ['billing' => null, 'technical' => null]),
    ])->classify();

    expect($response['department']->category)->toBe('billing')
        ->and($response['is_urgent'])->toBeInstanceOf(BooleanAnswer::class);
});

test('can fake classification with closure', function (): void {
    Classification::fake(fn (ClassificationPrompt $prompt) => [
        'is_urgent' => new BooleanAnswer($prompt->contains('ASAP') ? 1.0 : 0.0),
    ]);

    $urgent = Classification::of('Fix this ASAP')->question('is_urgent', new Boolean('Urgent?'))->classify();
    $calm = Classification::of('No rush')->question('is_urgent', new Boolean('Urgent?'))->classify();

    expect($urgent['is_urgent']->isTrue())->toBeTrue()
        ->and($calm['is_urgent']->isTrue())->toBeFalse();
});

test('can assert classified', function (): void {
    Classification::fake();

    Classification::of(['body' => 'Laravel is great'])->question('is_urgent', new Boolean('Urgent?'))->timeout(45)->classify(provider: Lab::TypeSafe);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('Laravel')
        && $prompt->asks('is_urgent')
        && $prompt->count() === 1
        && $prompt->timeout === 45);

    Classification::assertNotClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('department'));
});

test('can assert nothing classified', function (): void {
    Classification::fake();

    Classification::assertNothingClassified();
});

test('can prevent stray classifications', function (): void {
    Classification::fake()->preventStrayClassifications();

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify();
})->throws(RuntimeException::class);

test('missing answer throws', function (): void {
    Classification::fake();

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify()->answer('nope');
})->throws(InvalidArgumentException::class, 'No answer was returned for question [nope].');

test('non-classification providers throw', function (): void {
    Classification::fake();

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'openai');
})->throws(LogicException::class, 'does not support classification');
