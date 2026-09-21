<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;

beforeEach(function (): void {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

function fakeDecisionsResponse(): array
{
    return [
        'id' => 'dec_123',
        'model' => 'typesafe/jev-1.13',
        'provider' => 'TypeSafe',
        'answers' => [
            'is_urgent' => ['type' => 'noul', 'noul' => 0.96],
            'department' => [
                'type' => 'choice',
                'choice' => 'payments',
                'probabilities' => ['account' => 0.0, 'frontend' => 0.16, 'payments' => 0.84],
                'confidence' => 0.75,
            ],
            'urgency' => [
                'type' => 'score',
                'score' => 1.99,
                'probabilities' => ['0' => 0.0, '1' => 0.01, '2' => 0.99],
                'legend' => ['0' => 'Next release', '1' => 'This week', '2' => 'Blocking revenue'],
                'confidence' => 0.99,
            ],
        ],
        'usage' => ['input_tokens' => 312, 'output_tokens' => 48, 'cost' => 0.000013],
    ];
}

test('classification posts to the decisions endpoint in the system one wire format', function (): void {
    Http::fake(['*' => Http::response(fakeDecisionsResponse())]);

    Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
        ->questions([
            'is_urgent' => new Boolean('Does this message convey urgency?'),
            'department' => new Choice('Which team should own this ticket?', [
                'account' => 'Login, permissions, or profile issues.',
                'frontend' => 'Rendering or layout issues.',
                'payments' => null,
            ]),
            'urgency' => new Score('How urgent is this ticket?', [
                'Next release', 'This week', 'Blocking revenue',
            ]),
        ])
        ->classify(provider: 'openrouter', model: 'typesafe/jev-1.13');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://openrouter.ai/api/alpha/decisions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $body['model'] === 'typesafe/jev-1.13'
            && $body['state'] === 'Stripe connect keeps failing, losing sales, help ASAP'
            && $body['questions'] === [
                'is_urgent' => ['type' => 'noul', 'instructions' => 'Does this message convey urgency?'],
                'department' => [
                    'type' => 'choice',
                    'instructions' => 'Which team should own this ticket?',
                    'criteria' => ['account' => 'Login, permissions, or profile issues.', 'frontend' => 'Rendering or layout issues.', 'payments' => null],
                ],
                'urgency' => [
                    'type' => 'score',
                    'instructions' => 'How urgent is this ticket?',
                    'criteria' => ['Next release', 'This week', 'Blocking revenue'],
                ],
            ];
    });
});

test('decisions response is parsed into typed answers', function (): void {
    Http::fake(['*' => Http::response(fakeDecisionsResponse())]);

    $response = Classification::of('text')
        ->questions([
            'is_urgent' => new Boolean('Urgent?'),
            'department' => new Choice('Team?', ['account' => null, 'frontend' => null, 'payments' => null]),
            'urgency' => new Score('How urgent?', ['Next release', 'This week', 'Blocking revenue']),
        ])
        ->classify(provider: 'openrouter');

    expect($response)->toHaveCount(3)
        ->and($response['is_urgent'])->toBeInstanceOf(BooleanAnswer::class)
        ->and($response['is_urgent']->probability)->toBe(0.96)
        ->and($response['department'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($response['department']->choice)->toBe('payments')
        ->and($response['department']->probabilityOf('frontend'))->toBe(0.16)
        ->and($response['urgency'])->toBeInstanceOf(ScoreAnswer::class)
        ->and($response['urgency']->score)->toBe(1.99)
        ->and($response['urgency']->level())->toBe(2)
        ->and($response['urgency']->label())->toBe('Blocking revenue')
        ->and($response->usage->inputTokens)->toBe(312)
        ->and($response->meta->provider)->toBe('openrouter')
        ->and($response->meta->model)->toBe('typesafe/jev-1.13');
});

test('classification uses the default decisions model when none is specified', function (): void {
    Http::fake(['*' => Http::response(fakeDecisionsResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'openrouter');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === '~typesafe/jev-latest');
});

test('decisions requests use the configured base url', function (): void {
    config(['ai.providers.openrouter' => [...config('ai.providers.openrouter'), 'url' => 'http://localhost:8080/api/v1']]);

    Http::fake(['*' => Http::response(fakeDecisionsResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'openrouter');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/api/alpha/decisions');
});
