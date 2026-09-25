<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Classification;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

enum CollectionDecidePriority
{
    case Low;
    case High;
}

enum CollectionDecideChannel: string
{
    case Email = 'email';
    case Phone = 'phone';
}

enum CollectionDecideLevel: int
{
    case Minor = 1;
    case Major = 2;
}

function collectionDecideAnswer(string $choice, array $probabilities): array
{
    return ['decision' => new ChoiceAnswer($choice, $probabilities, max($probabilities))];
}

test('a list of strings decides between its values', function (): void {
    Classification::fake([collectionDecideAnswer('refunds', ['refunds' => 0.9, 'marketing' => 0.05, 'sales' => 0.05])]);

    $department = collect(['refunds', 'marketing', 'sales'])
        ->decide('Which department should this be routed to?', 'I want my money back.');

    expect($department)->toBe('refunds');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('I want my money back.')
        && $prompt->questions['decision']->instructions === 'Which department should this be routed to?'
        && $prompt->questions['decision']->options === ['refunds' => null, 'marketing' => null, 'sales' => null]);
});

test('a string keyed collection of strings uses its values as descriptions and returns the key', function (): void {
    Classification::fake([collectionDecideAnswer('sales', ['refunds' => 0.2, 'sales' => 0.8])]);

    $department = collect([
        'refunds' => 'Customer wants money back',
        'sales' => 'Pricing, quotes, upgrades',
    ])->decide('Where should this go?', 'Can I get a quote?');

    expect($department)->toBe('sales');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['decision']->options === [
        'refunds' => 'Customer wants money back',
        'sales' => 'Pricing, quotes, upgrades',
    ]);
});

test('a string keyed collection is treated as items once a resolver is given', function (): void {
    Classification::fake([collectionDecideAnswer('SALES', ['REFUNDS' => 0.1, 'SALES' => 0.9])]);

    $department = collect(['a' => 'refunds', 'b' => 'sales'])
        ->decide('Where should this go?', 'Can I get a quote?', by: fn (string $item): string => strtoupper($item));

    expect($department)->toBe('sales');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['decision']->options === ['REFUNDS' => null, 'SALES' => null]);
});

test('stringable names are cast to strings', function (): void {
    Classification::fake([collectionDecideAnswer('sales', ['refunds' => 0.1, 'sales' => 0.9])]);

    $sales = str('sales');

    expect(collect([str('refunds'), $sales])->decide('Where should this go?', 'Can I get a quote?'))->toBe($sales);
});

test('items are labeled by a field and described by a field', function (): void {
    Classification::fake([collectionDecideAnswer('Billing', ['Support' => 0.3, 'Billing' => 0.7])]);

    $support = (object) ['name' => 'Support', 'description' => 'Technical problems'];
    $billing = (object) ['name' => 'Billing', 'description' => 'Invoices and payments'];

    $department = collect([$support, $billing])->decide(
        'Which department should handle this?',
        'My invoice is wrong.',
        by: 'name',
        describe: 'description',
    );

    expect($department)->toBe($billing);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['decision']->options === [
        'Support' => 'Technical problems',
        'Billing' => 'Invoices and payments',
    ]);
});

test('items may be labeled and described by closures or described by several fields', function (): void {
    Classification::fake([
        collectionDecideAnswer('support', ['support' => 0.6, 'billing' => 0.4]),
        collectionDecideAnswer('billing', ['support' => 0.4, 'billing' => 0.6]),
    ]);

    $departments = collect([
        ['slug' => 'support', 'description' => 'Technical problems', 'topics' => 'bugs, outages'],
        ['slug' => 'billing', 'description' => 'Invoices and payments', 'topics' => 'refunds, invoices'],
    ]);

    $department = $departments->decide(
        'Which department should handle this?',
        'The site is down.',
        by: fn (array $department): string => $department['slug'],
        describe: fn (array $department): string => "{$department['description']} Topics: {$department['topics']}.",
    );

    expect($department['slug'])->toBe('support');

    $department = $departments->decide('Which department?', 'Refund please.', by: 'slug', describe: ['description', 'topics']);

    expect($department['slug'])->toBe('billing');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['decision']->options['support'] === 'Technical problems Topics: bugs, outages.');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->questions['decision']->options['billing'] === [
        'description' => 'Invoices and payments',
        'topics' => 'refunds, invoices',
    ]);
});

test('enum cases are labeled automatically and the case is returned', function (): void {
    Classification::fake([
        collectionDecideAnswer('High', ['Low' => 0.1, 'High' => 0.9]),
        collectionDecideAnswer('phone', ['email' => 0.2, 'phone' => 0.8]),
        collectionDecideAnswer('Major', ['Minor' => 0.3, 'Major' => 0.7]),
    ]);

    expect(collect(CollectionDecidePriority::cases())->decide('How urgent is this?', 'Production is down!'))
        ->toBe(CollectionDecidePriority::High)
        ->and(collect(CollectionDecideChannel::cases())->decide('How should we reply?', 'Call me back.'))
        ->toBe(CollectionDecideChannel::Phone)
        ->and(collect(CollectionDecideLevel::cases())->decide('How severe is this?', 'Data loss.'))
        ->toBe(CollectionDecideLevel::Major);
});

test('null is returned when the chosen option falls below the threshold', function (): void {
    Classification::fake([
        collectionDecideAnswer('sales', ['refunds' => 0.45, 'sales' => 0.55]),
        collectionDecideAnswer('sales', ['refunds' => 0.45, 'sales' => 0.55]),
    ]);

    $departments = collect(['refunds', 'sales']);

    expect($departments->decide('Where should this go?', 'Hmm.', threshold: 0.6))->toBeNull()
        ->and($departments->decide('Where should this go?', 'Hmm.', threshold: 0.5))->toBe('sales');
});

test('the text may be structured', function (): void {
    Classification::fake([collectionDecideAnswer('sales', ['refunds' => 0.1, 'sales' => 0.9])]);

    collect(['refunds', 'sales'])->decide('Where should this go?', ['subject' => 'Upgrade', 'body' => 'Need more seats.']);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->contains('Need more seats.'));
});

test('the macro passes through provider options', function (): void {
    Classification::fake();

    collect(['refunds', 'sales'])->decide('Where should this go?', 'Some text.', provider: Lab::TypeSafe, model: 'custom-model', timeout: 45);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->model === 'custom-model'
        && $prompt->timeout === 45);
});

test('items without a resolvable name require a by field or closure', function (): void {
    Classification::fake();

    collect([(object) ['name' => 'Support'], (object) ['name' => 'Billing']])->decide('Which?', 'Text.');
})->throws(InvalidArgumentException::class, 'Unable to determine an option name');

test('items resolving to the same name are rejected', function (): void {
    Classification::fake();

    collect([['name' => 'Support'], ['name' => 'Support']])->decide('Which?', 'Text.', by: 'name');
})->throws(InvalidArgumentException::class, 'Multiple items resolve to the option name [Support].');

test('at least two items are required', function (): void {
    Classification::fake();

    (new Collection(['refunds']))->decide('Which?', 'Text.');
})->throws(InvalidArgumentException::class, 'at least two options');
