<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;

function layaQuestions(): array
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

function fakeLayaResponse(): array
{
    return [
        'model' => 'laya-rl-agent',
        'answers' => [
            'is_urgent' => [
                'type' => 'noul',
                'noul' => 0.91,
                'confidence' => 0.91,
                'answer_confidence' => 0.88,
                'action' => ['act_probability' => 0.97],
            ],
            'department' => [
                'type' => 'choice',
                'choice' => 'technical',
                'probabilities' => ['billing' => 0.1, 'technical' => 0.8, 'sales' => 0.1],
                'confidence' => 0.42,
                'answer_confidence' => 0.8,
                'action' => ['act_probability' => 0.95],
            ],
            'frustration' => [
                'type' => 'score',
                'score' => 1.4,
                'legend' => ['0' => 'Calm, stating facts', '1' => 'Frustrated but civil', '2' => 'Very angry'],
                'probabilities' => ['0' => 0.1, '1' => 0.4, '2' => 0.5],
                'confidence' => 0.2,
                'answer_confidence' => 0.5,
                'action' => ['act_probability' => 0.9],
            ],
        ],
        'usage' => ['input_tokens' => 96, 'output_tokens' => 0],
        'routing' => ['model' => 'english', 'repo' => 'convaiinnovations/laya', 'reason' => 'English Latin text'],
    ];
}

test('classification request is sent to the local laya server without authorization by default', function (): void {
    Http::fake(['*' => Http::response(fakeLayaResponse())]);

    Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
        ->questions(layaQuestions())
        ->classify(provider: Lab::Laya);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'http://localhost:8000/v1/systemone'
            && ! $request->hasHeader('Authorization')
            && $body['model'] === 'auto'
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

test('classification requests send the configured api key and base url', function (): void {
    config(['ai.providers.laya' => [
        ...config('ai.providers.laya'),
        'key' => 'secret',
        'url' => 'https://laya.internal/v1/',
    ]]);

    Http::fake(['*' => Http::response(fakeLayaResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'laya');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://laya.internal/v1/systemone'
        && $request->hasHeader('Authorization', 'Bearer secret'));
});

test('classification requests may pin a laya checkpoint', function (): void {
    Http::fake(['*' => Http::response(fakeLayaResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'laya', model: 'multilingual');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'multilingual');
});

test('laya response is parsed into typed answers', function (): void {
    Http::fake(['*' => Http::response(fakeLayaResponse())]);

    $response = Classification::of('text')->questions(layaQuestions())->classify(provider: 'laya');

    expect($response)->toHaveCount(3)
        ->and($response['is_urgent'])->toBeInstanceOf(BooleanAnswer::class)
        ->and($response['is_urgent']->probability)->toBe(0.91)
        ->and($response['department'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($response['department']->choice)->toBe('technical')
        ->and($response['department']->confidence)->toBe(0.42)
        ->and($response['frustration'])->toBeInstanceOf(ScoreAnswer::class)
        ->and($response['frustration']->score)->toBe(1.4)
        ->and($response['frustration']->label())->toBe('Very angry')
        ->and($response->usage->inputTokens)->toBe(96)
        ->and($response->meta->provider)->toBe('laya')
        ->and($response->meta->model)->toBe('english');
});

test('the reported model falls back to the response model without routing', function (): void {
    $payload = fakeLayaResponse();

    unset($payload['routing']);

    Http::fake(['*' => Http::response($payload)]);

    $response = Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'laya');

    expect($response->meta->model)->toBe('laya-rl-agent');
});

test('laya token budgets are sent as provider options', function (): void {
    Http::fake(['*' => Http::response(fakeLayaResponse())]);

    Classification::of('a long document')
        ->question('is_urgent', new Boolean('Urgent?'))
        ->withProviderOptions(['max_len' => 4096])
        ->classify(provider: 'laya');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['max_len'] === 4096);
});

test('on-demand laya providers do not require an api key', function (): void {
    Http::fake(['*' => Http::response(fakeLayaResponse())]);

    $provider = Ai::build(['driver' => 'laya', 'url' => 'http://gpu-box:8000/v1']);

    $response = $provider->classify('text', ['is_urgent' => new Boolean('Urgent?')]);

    expect($response['is_urgent']->probability)->toBe(0.91);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://gpu-box:8000/v1/systemone'
        && ! $request->hasHeader('Authorization'));
});

test('a busy laya server throws provider overloaded exception', function (): void {
    Http::fake(['*' => Http::response(['detail' => 'server busy, try again later'], 503, ['Retry-After' => '1'])]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'laya');
})->throws(ProviderOverloadedException::class);

test('an unreachable laya server throws provider connection exception', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'laya');
})->throws(ProviderConnectionException::class);
