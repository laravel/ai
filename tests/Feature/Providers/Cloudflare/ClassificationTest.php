<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\Classified;
use Laravel\Ai\Events\Classifying;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Providers\CloudflareProvider;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;

beforeEach(function (): void {
    config(['ai.providers.cloudflare' => [
        ...config('ai.providers.cloudflare'),
        'key' => 'test-token',
        'account_id' => 'test-account',
    ]]);

    Http::preventStrayRequests();
});

function cloudflareQuestions(): array
{
    return [
        'urgent' => new Boolean('Urgent?', ['true' => 'Requires immediate attention', 'false' => 'Can wait']),
        'department' => new Choice('Which team should handle this?', [
            'billing' => 'Payments and invoices',
            'technical' => 'Errors and outages',
            'sales' => null,
        ]),
        'severity' => new Score('Customer impact?', ['None', 'Minor', 'Major', 'Critical']),
    ];
}

function cloudflareResponse(string $model = 'clef'): array
{
    return [
        'result' => [
            'model' => $model,
            'answers' => [
                'urgent' => ['type' => 'noul', 'noul' => 0.92],
                'department' => [
                    'type' => 'choice',
                    'choice' => 'technical',
                    'probabilities' => ['billing' => 0.08, 'technical' => 0.85, 'sales' => 0.07],
                    'confidence' => 0.82,
                ],
                'severity' => [
                    'type' => 'score',
                    'score' => 2.6,
                    'legend' => ['0' => 'None', '1' => 'Minor', '2' => 'Major', '3' => 'Critical'],
                    'probabilities' => ['3' => 0.65, '0' => 0.0, '2' => 0.3, '1' => 0.05],
                    'confidence' => 0.78,
                ],
            ],
            'usage' => ['input_tokens' => 312, 'output_tokens' => 48],
        ],
        'success' => true,
        'errors' => [],
        'messages' => [],
    ];
}

test('classification sends the Workers AI URL, token and System One payload', function (string $model, string $selector): void {
    Http::fake(['*' => Http::response(cloudflareResponse($selector))]);

    Classification::of('Checkout has been failing for the last hour.')
        ->questions(cloudflareQuestions())
        ->classify(provider: 'cloudflare', model: $model);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.cloudflare.com/client/v4/accounts/test-account/ai/run/'.$model
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->data() === [
            'model' => $selector,
            'state' => 'Checkout has been failing for the last hour.',
            'questions' => [
                'urgent' => [
                    'type' => 'noul',
                    'instructions' => 'Urgent?',
                    'criteria' => ['true' => 'Requires immediate attention', 'false' => 'Can wait'],
                ],
                'department' => [
                    'type' => 'choice',
                    'instructions' => 'Which team should handle this?',
                    'criteria' => ['billing' => 'Payments and invoices', 'technical' => 'Errors and outages', 'sales' => null],
                ],
                'severity' => [
                    'type' => 'score',
                    'instructions' => 'Customer impact?',
                    'criteria' => ['None', 'Minor', 'Major', 'Critical'],
                ],
            ],
        ]);
})->with([
    'Clef' => ['@cf/cloudflare/clef', 'clef'],
    'Clef-flash' => ['@cf/cloudflare/clef-flash', 'clef-flash'],
]);

test('classification unwraps typed answers, probabilities, confidence, usage and metadata', function (string $selector): void {
    Http::fake(['*' => Http::response([
        ...cloudflareResponse($selector),
        'model' => 'outer-model',
        'usage' => ['input_tokens' => 999, 'output_tokens' => 999],
    ])]);

    $response = Classification::of('text')->questions(cloudflareQuestions())
        ->classify(provider: Lab::Cloudflare, model: '@cf/cloudflare/'.$selector);

    expect($response)->toBeInstanceOf(ClassificationResponse::class)->toHaveCount(3)
        ->and($response['urgent'])->toBeInstanceOf(BooleanAnswer::class)
        ->and($response['urgent']->probability)->toBe(0.92)
        ->and($response['urgent']->isTrue())->toBeTrue()
        ->and($response['department'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($response['department']->choice)->toBe('technical')
        ->and($response['department']->probabilities)->toBe(['billing' => 0.08, 'technical' => 0.85, 'sales' => 0.07])
        ->and($response['department']->confidence)->toBe(0.82)
        ->and($response['severity'])->toBeInstanceOf(ScoreAnswer::class)
        ->and($response['severity']->score)->toBe(2.6)
        ->and($response['severity']->probabilities)->toBe([0 => 0, 1 => 0.05, 2 => 0.3, 3 => 0.65])
        ->and($response['severity']->legend)->toBe(['None', 'Minor', 'Major', 'Critical'])
        ->and($response['severity']->confidence)->toBe(0.78)
        ->and($response['severity']->label())->toBe('Critical')
        ->and($response->usage->inputTokens)->toBe(312)
        ->and($response->usage->outputTokens)->toBe(48)
        ->and($response->meta->provider)->toBe('cloudflare')
        ->and($response->meta->model)->toBe($selector);
})->with(['clef', 'clef-flash']);

test('classification sends structured state without converting it to text', function (array $state): void {
    Http::fake(['*' => Http::response(cloudflareResponse())]);

    Classification::of($state)->question('urgent', new Boolean('Urgent?'))->classify(provider: 'cloudflare');

    Http::assertSent(fn (Request $request): bool => $request['state'] === $state
        && $request['questions']['urgent'] === ['type' => 'noul', 'instructions' => 'Urgent?']);
})->with([
    'object' => [['subject' => 'Help', 'messages' => ['first', 'second'], 'attempts' => 3]],
    'list' => [[['role' => 'user', 'content' => 'Help']]],
]);

test('classification uses Clef by default without changing existing defaults', function (): void {
    Http::fake(['*' => Http::response(cloudflareResponse())]);

    Classification::of('text')->question('urgent', new Boolean('Urgent?'))->classify(provider: 'cloudflare');

    expect(Ai::classificationProvider('cloudflare'))->toBeInstanceOf(CloudflareProvider::class)
        ->and(config('ai.default'))->toBe('openai')
        ->and(config('ai.default_for_classification'))->toBe('typesafe');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/@cf/cloudflare/clef') && $request['model'] === 'clef');
});

test('classification supports a configured default model', function (): void {
    config(['ai.providers.cloudflare.models.classification.default' => '@cf/cloudflare/clef-flash']);

    Http::fake(['*' => Http::response(cloudflareResponse('clef-flash'))]);

    Classification::of('text')->question('urgent', new Boolean('Urgent?'))->classify(provider: 'cloudflare');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/@cf/cloudflare/clef-flash')
        && $request['model'] === 'clef-flash');
});

test('classification falls back to Clef when no model configuration is present', function (): void {
    config(['ai.providers.cloudflare.models' => []]);

    expect(Ai::classificationProvider('cloudflare')->defaultClassificationModel())->toBe('@cf/cloudflare/clef');
});

test('classification supports configured URL, account, headers, timeout and protected provider options', function (): void {
    config([
        'ai.providers.cloudflare.url' => 'https://cloudflare.test/client/v4/',
        'ai.providers.cloudflare.account_id' => 'another-account',
        'ai.providers.cloudflare.headers' => ['X-Custom' => 'configured'],
    ]);

    Http::fake(function (Request $request, array $options) {
        expect($options['timeout'])->toBe(45);

        return Http::response(cloudflareResponse());
    });

    Classification::of('text')->question('urgent', new Boolean('Urgent?'))->timeout(45)
        ->withProviderOptions([
            'model' => 'hijacked',
            'state' => 'hijacked',
            'questions' => [],
            'beam_width' => 4,
        ])
        ->withHeaders(['X-Request' => 'custom'])
        ->classify(provider: 'cloudflare');

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://cloudflare.test/client/v4/accounts/another-account/ai/run/@cf/cloudflare/clef'
        && $request->hasHeader('X-Custom', 'configured')
        && $request->hasHeader('X-Request', 'custom')
        && $request['model'] === 'clef'
        && $request['state'] === 'text'
        && $request['questions'] === ['urgent' => ['type' => 'noul', 'instructions' => 'Urgent?']]
        && $request['beam_width'] === 4);
});

test('classification falls back when optional answer fields and usage are absent', function (): void {
    Http::fake(['*' => Http::response([
        'success' => true,
        'errors' => [],
        'result' => ['answers' => [
            'department' => ['type' => 'choice', 'choice' => 'technical'],
            'severity' => ['type' => 'score', 'score' => 2.0],
        ]],
    ])]);

    $response = Classification::of('text')->questions(cloudflareQuestions())->classify(provider: 'cloudflare');

    expect($response['department']->probabilities)->toBe([])
        ->and($response['department']->confidence)->toBeNull()
        ->and($response['severity']->probabilities)->toBe([])
        ->and($response['severity']->confidence)->toBeNull()
        ->and($response['severity']->legend)->toBe(['None', 'Minor', 'Major', 'Critical'])
        ->and($response->usage->inputTokens)->toBe(0)
        ->and($response->usage->outputTokens)->toBe(0)
        ->and($response->meta->model)->toBe('@cf/cloudflare/clef');
});

test('unsupported models fail before sending a request', function (): void {
    Classification::of('text')->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: '@cf/meta/llama-3.1-8b-instruct');
})->throws(InvalidArgumentException::class, 'Unsupported Cloudflare classification model');

test('a missing account fails before sending a request', function (): void {
    config(['ai.providers.cloudflare.account_id' => null]);

    Classification::of('text')->question('urgent', new Boolean('Urgent?'))->classify(provider: 'cloudflare');
})->throws(InvalidArgumentException::class, 'A Cloudflare account ID is required');

test('HTTP authentication and request errors are not classified successfully', function (int $status): void {
    Http::fake(['*' => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Request rejected']]], $status)]);

    Classification::of('text')->question('urgent', new Boolean('Urgent?'))->classify(provider: 'cloudflare');
})->with([400, 401, 403, 404])->throws(RequestException::class);

test('rate limiting and capacity errors use the existing rate limited exception', function (int $code): void {
    Http::fake(['*' => Http::response(['success' => false, 'errors' => [['code' => $code, 'message' => 'Limited']]], 429)]);

    Classification::of('text')->question('urgent', new Boolean('Urgent?'))->classify(provider: 'cloudflare');
})->with([3036, 3040])->throws(RateLimitedException::class);

test('transient HTTP errors use the existing overloaded exception', function (int $status): void {
    Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'Temporarily unavailable']]], $status)]);

    Classification::of('text')->question('urgent', new Boolean('Urgent?'))->classify(provider: 'cloudflare');
})->with([408, 500, 502, 503, 504, 520, 521, 522, 523, 524])->throws(ProviderOverloadedException::class);

test('connection errors use the existing connection exception', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    Classification::of('text')->question('urgent', new Boolean('Urgent?'))->classify(provider: 'cloudflare');
})->throws(ProviderConnectionException::class);

test('an unsuccessful REST envelope throws even on HTTP 200', function (array $data): void {
    Http::fake(['*' => Http::response($data)]);

    Classification::of('text')->question('urgent', new Boolean('Urgent?'))->classify(provider: 'cloudflare');
})->with([
    'unsuccessful' => [['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']], 'result' => null]],
    'errors with success' => [[...cloudflareResponse(), 'errors' => [['code' => 3007, 'message' => 'Timeout']]]],
])->throws(RequestException::class);

test('malformed REST responses cannot silently produce an empty classification', function (mixed $data): void {
    Http::fake(['*' => Http::response($data)]);

    Classification::of('text')->question('urgent', new Boolean('Urgent?'))->classify(provider: 'cloudflare');
})->with([
    'unwrapped' => [cloudflareResponse()['result']],
    'missing result' => [['success' => true]],
    'null result' => [['success' => true, 'result' => null]],
    'missing answers' => [['success' => true, 'result' => ['model' => 'clef']]],
    'empty answers' => [['success' => true, 'result' => ['answers' => []]]],
    'non-object answers' => [['success' => true, 'result' => ['answers' => 'invalid']]],
    'invalid JSON' => ['not JSON'],
])->throws(UnexpectedValueException::class, 'Cloudflare returned an invalid classification response.');

test('classification uses the existing events', function (): void {
    Event::fake([Classifying::class, Classified::class]);
    Http::fake(['*' => Http::response(cloudflareResponse())]);

    $response = Classification::of('text')->questions(cloudflareQuestions())->classify(provider: 'cloudflare');

    Event::assertDispatched(Classifying::class, fn (Classifying $event): bool => $event->provider->name() === 'cloudflare'
        && $event->model === '@cf/cloudflare/clef');
    Event::assertDispatched(Classified::class, fn (Classified $event): bool => $event->response === $response);
});

test('classification fake works without Cloudflare credentials or HTTP requests', function (string $model): void {
    config(['ai.providers.cloudflare.key' => null, 'ai.providers.cloudflare.account_id' => null]);
    Http::fake();

    Classification::fake([['urgent' => new BooleanAnswer(0.9)]]);

    $response = Classification::of(['message' => 'Help'])->questions(cloudflareQuestions())
        ->classify(provider: Lab::Cloudflare, model: $model);

    expect($response['urgent']->probability)->toBe(0.9)
        ->and($response['department'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($response['severity'])->toBeInstanceOf(ScoreAnswer::class)
        ->and($response->meta->provider)->toBe('cloudflare')
        ->and($response->meta->model)->toBe($model);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->provider->name() === 'cloudflare'
        && $prompt->model === $model && $prompt->contains('Help'));
    Http::assertNothingSent();
})->with(['@cf/cloudflare/clef', '@cf/cloudflare/clef-flash']);

test('classification fails over from Cloudflare using the existing provider events', function (int $status): void {
    Event::fake([ProviderFailedOver::class]);
    config(['ai.providers.typesafe.key' => 'test-key']);

    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => false], $status),
        'api.typesafe.ai/*' => Http::response([
            'model' => 'jev-latest',
            'answers' => ['urgent' => ['type' => 'noul', 'noul' => 0.9]],
        ]),
    ]);

    $response = Classification::of('text')->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: ['cloudflare' => '@cf/cloudflare/clef-flash', 'typesafe' => 'jev-latest']);

    expect($response['urgent']->probability)->toBe(0.9)
        ->and($response->meta->provider)->toBe('typesafe');

    Http::assertSentCount(2);
    Event::assertDispatched(ProviderFailedOver::class, fn (ProviderFailedOver $event): bool => $event->provider->name() === 'cloudflare'
        && $event->model === '@cf/cloudflare/clef-flash');
})->with([408, 429, 503]);

test('Cloudflare can be the failover destination', function (): void {
    config(['ai.providers.typesafe.key' => 'test-key']);

    Http::fake([
        'api.typesafe.ai/*' => Http::response([], 503),
        'api.cloudflare.com/*' => Http::response(cloudflareResponse()),
    ]);

    $response = Classification::of('text')->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: ['typesafe', 'cloudflare']);

    expect($response->meta->provider)->toBe('cloudflare')->and($response['urgent']->probability)->toBe(0.92);
    Http::assertSentCount(2);
});
