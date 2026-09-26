<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;

beforeEach(function (): void {
    config(['ai.providers.von' => [
        ...config('ai.providers.von'),
        'key' => 'test-key',
    ]]);
});

function vonTriageQuestions(): array
{
    return [
        'is_urgent' => new Boolean('Does this message convey urgency?'),
        'department' => new Choice('Which team should handle this?', [
            'billing' => 'Payments, invoicing, refunds',
            'technical' => 'Bugs, outages, integrations',
            'sales' => null,
        ]),
        'frustration' => new Score('How frustrated is the customer?', [
            'Calm, stating facts', 'Frustrated but civil', 'Very angry',
        ]),
    ];
}

function fakeVonResponse(): array
{
    return [
        'model' => 'von-1.1.0',
        'answers' => [
            'is_urgent' => ['type' => 'noul', 'noul' => 0.92],
            'department' => [
                'type' => 'choice',
                'choice' => 'technical',
                'probabilities' => ['billing' => 0.08, 'technical' => 0.85, 'sales' => 0.07],
                'confidence' => 0.82,
            ],
            'frustration' => [
                'type' => 'score',
                'score' => 1.6,
                'legend' => ['0' => 'Calm, stating facts', '1' => 'Frustrated but civil', '2' => 'Very angry'],
                'probabilities' => ['2' => 0.65, '0' => 0.05, '1' => 0.3],
                'confidence' => 0.78,
            ],
        ],
        'usage' => ['input_tokens' => 312, 'output_tokens' => 48],
    ];
}

test('classification request maps questions to the system one wire format', function (): void {
    Http::fake(['*' => Http::response(fakeVonResponse())]);

    Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
        ->questions(vonTriageQuestions())
        ->classify(provider: 'von', model: 'von-1.1.0');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'http://localhost:8000/v1/systemone'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $body['model'] === 'von-1.1.0'
            && $body['state'] === 'Stripe connect keeps failing, losing sales, help ASAP'
            && $body['questions'] === [
                'is_urgent' => ['type' => 'noul', 'instructions' => 'Does this message convey urgency?'],
                'department' => [
                    'type' => 'choice',
                    'instructions' => 'Which team should handle this?',
                    'criteria' => ['billing' => 'Payments, invoicing, refunds', 'technical' => 'Bugs, outages, integrations', 'sales' => null],
                ],
                'frustration' => [
                    'type' => 'score',
                    'instructions' => 'How frustrated is the customer?',
                    'criteria' => ['Calm, stating facts', 'Frustrated but civil', 'Very angry'],
                ],
            ];
    });
});

test('boolean criteria are sent when given', function (): void {
    Http::fake(['*' => Http::response(fakeVonResponse())]);

    Classification::of('Build finished')
        ->question('passed', new Boolean('Did the build succeed?', [
            'true' => 'exit code 0',
            'false' => 'any non-zero exit code',
        ]))
        ->classify(provider: 'von');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['questions']['passed'] === [
        'type' => 'noul',
        'instructions' => 'Did the build succeed?',
        'criteria' => ['true' => 'exit code 0', 'false' => 'any non-zero exit code'],
    ]);
});

test('structured state is sent as an object', function (): void {
    Http::fake(['*' => Http::response(fakeVonResponse())]);

    Classification::of(['subject' => 'Help', 'messages' => ['first', 'second']])
        ->question('is_urgent', new Boolean('Urgent?'))
        ->classify(provider: 'von');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['state'] === ['subject' => 'Help', 'messages' => ['first', 'second']]);
});

test('classification response is parsed into typed answers', function (): void {
    Http::fake(['*' => Http::response(fakeVonResponse())]);

    $response = Classification::of('text')->questions(vonTriageQuestions())->classify(provider: 'von', model: 'von-1.1.0');

    expect($response)->toHaveCount(3)
        ->and($response['is_urgent'])->toBeInstanceOf(BooleanAnswer::class)
        ->and($response['is_urgent']->probability)->toBe(0.92)
        ->and($response['is_urgent']->isTrue())->toBeTrue()
        ->and($response['is_urgent']->isTrue(0.95))->toBeFalse()
        ->and($response['department'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($response['department']->choice)->toBe('technical')
        ->and($response['department']->probabilityOf('billing'))->toBe(0.08)
        ->and($response['department']->confidence)->toBe(0.82)
        ->and($response['frustration'])->toBeInstanceOf(ScoreAnswer::class)
        ->and($response['frustration']->score)->toBe(1.6)
        ->and($response['frustration']->level())->toBe(2)
        ->and($response['frustration']->probabilities)->toBe([0 => 0.05, 1 => 0.3, 2 => 0.65])
        ->and($response['frustration']->label())->toBe('Very angry')
        ->and($response['frustration']->normalized())->toBe(0.8)
        ->and($response->usage->inputTokens)->toBe(312)
        ->and($response->usage->outputTokens)->toBe(48)
        ->and($response->meta->provider)->toBe('von')
        ->and($response->meta->model)->toBe('von-1.1.0');
});

test('unknown answer types are skipped', function (): void {
    Http::fake(['*' => Http::response([
        'model' => 'von-1.1.0',
        'answers' => [
            'is_urgent' => ['type' => 'noul', 'noul' => 0.5],
            'future' => ['type' => 'ranking', 'ranking' => []],
        ],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ])]);

    $response = Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'von');

    expect($response->collect()->keys()->all())->toBe(['is_urgent']);
});

test('classification uses default model when none specified', function (): void {
    Http::fake(['*' => Http::response(fakeVonResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'von');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'von-1.1.0');
});

test('classification requests use the configured base url', function (): void {
    config(['ai.providers.von' => [...config('ai.providers.von'), 'url' => 'http://localhost:8080/v1/']]);

    Http::fake(['*' => Http::response(fakeVonResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'von');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/v1/systemone');
});

test('provider options may not override the core classification payload', function (): void {
    Http::fake(['*' => Http::response(fakeVonResponse())]);

    Classification::of('text')
        ->question('is_urgent', new Boolean('Urgent?'))
        ->withProviderOptions(['model' => 'hijacked', 'state' => 'hijacked', 'beam_width' => 4])
        ->classify(provider: 'von', model: 'von-1.1.0');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'von-1.1.0' && $body['state'] === 'text' && $body['beam_width'] === 4;
    });
});

test('classification throws when the API returns an error', function (): void {
    Http::fake(['*' => Http::response(['detail' => ['error_type' => 'authentication_error']], 401)]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'von');
})->throws(RequestException::class);

test('classification rate limit response throws rate limited exception', function (): void {
    Http::fake(['localhost:8000/*' => Http::response(['detail' => 'rate limit exceeded'], 429)]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'von');
})->throws(RateLimitedException::class);

test('classification overloaded response throws provider overloaded exception', function (int $status): void {
    Http::fake(['localhost:8000/*' => Http::response(['detail' => 'overloaded'], $status)]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'von');
})->with([529, 503])->throws(ProviderOverloadedException::class);
