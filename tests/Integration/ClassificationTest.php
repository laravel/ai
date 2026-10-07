<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Files\Image;

test('states can be classified', function (string $provider, string $apiKey): void {
    requiresApiKey($apiKey);

    Event::fake();

    $response = Classification::of("I've been trying to connect my Stripe account for 3 days and it keeps failing. I'm losing sales. Please help ASAP.")
        ->questions([
            'is_urgent' => new Boolean('Does this message convey urgency?'),
            'department' => new Choice('Which team should handle this?', [
                'billing' => 'Payments, invoicing, refunds',
                'technical' => 'Bugs, outages, integrations',
                'sales' => 'Pricing, plans, upgrades',
            ]),
            'frustration' => new Score('How frustrated is the customer?', [
                'Calm, stating facts', 'Frustrated but civil', 'Very angry',
            ]),
        ])->classify(provider: $provider);

    expect($response['is_urgent']->isTrue())->toBeTrue()
        ->and($response['department']->choice)->toBe('technical')
        ->and(round(array_sum($response['department']->probabilities), 1))->toBe(1.0)
        ->and($response['frustration']->score)->toBeGreaterThan(0.5)
        ->and($response->usage->inputTokens)->toBeGreaterThan(0)
        ->and($response->meta->provider)->toBe($provider);

    Event::assertDispatched(Classifying::class);
    Event::assertDispatched(Classified::class);
})->with('classification-providers');

test('images can be classified alongside the state', function (string $provider, string $apiKey, string $file, string $color): void {
    requiresApiKey($apiKey);

    $response = Classification::of('A product photo uploaded by a customer.', [Image::fromPath(__DIR__.'/../Fixtures/Images/'.$file)])
        ->questions([
            'is_red' => new Boolean('Is the background of the image red?'),
            'color' => new Choice('What color is the background of the image?', [
                'red' => null,
                'green' => null,
                'blue' => null,
            ]),
        ])->classify(provider: $provider);

    expect($response['is_red']->isTrue())->toBe($color === 'red')
        ->and($response['color']->choice)->toBe($color);
})->with('classification-image-providers')->with([
    'png' => ['red.png', 'red'],
    'jpeg' => ['blue.jpg', 'blue'],
]);

test('hot dog or not hot dog', function (string $provider, string $apiKey, string $file, bool $isHotDog): void {
    requiresApiKey($apiKey);

    $response = Classification::of('A photo of food.', [Image::fromPath(__DIR__.'/../Fixtures/Images/'.$file)])
        ->question('hot_dog', new Boolean('Is this a hot dog?'))
        ->classify(provider: $provider);

    expect($response['hot_dog']->isTrue())->toBe($isHotDog);
})->with('classification-image-providers')->with([
    'hot dog' => ['hotdog.jpg', true],
    'not hot dog' => ['pizza.jpg', false],
]);
