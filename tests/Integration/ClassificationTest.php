<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;

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
        ->and($response->usage->promptTokens)->toBeGreaterThan(0)
        ->and($response->meta->provider)->toBe($provider);

    Event::assertDispatched(Classifying::class);
    Event::assertDispatched(Classified::class);
})->with('classification-providers');
