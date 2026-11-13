<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InvalidApiKeyException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\FinishReason;
use Tests\Fixtures\Agents\AssistantAgent;

test('http error response throws request exception', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 400,
                'message' => 'Invalid value at contents',
                'status' => 'INVALID_ARGUMENT',
            ],
        ], 400),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(RequestException::class);

test('rate limit response throws rate limited exception', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 429,
                'message' => 'Resource has been exhausted',
                'status' => 'RESOURCE_EXHAUSTED',
            ],
        ], 429),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(RateLimitedException::class);

test('overloaded response throws provider overloaded exception', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 503,
                'message' => 'The model is overloaded. Please try again later.',
                'status' => 'UNAVAILABLE',
            ],
        ], 503),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(ProviderOverloadedException::class);

test('transient upstream errors fail over as overloaded', function (int $status): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['code' => $status, 'message' => 'The service is temporarily unavailable.'],
        ], $status),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->with([502, 504, 520, 522, 524])->throws(ProviderOverloadedException::class);

test('error in 200 response throws ai exception', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 'internal',
                'message' => 'Internal server error',
            ],
        ], 200),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(AiException::class, 'Gemini Error');

test('a failed interaction throws ai exception', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'id' => 'int_123',
            'status' => 'failed',
            'steps' => [],
            'errors' => [['code' => 'internal', 'message' => 'The model stopped responding.']],
        ]),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(AiException::class, 'Gemini Error: [internal] The model stopped responding.');

test('a withheld answer is reported as a content filter finish reason', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'id' => 'int_123',
            'status' => 'failed',
            'steps' => [],
            'errors' => [['code' => 'safety', 'message' => 'The response was blocked.']],
        ]),
    ]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

    expect($response->steps->last()->finishReason)->toBe(FinishReason::ContentFilter);
});

test('connection failure throws a failoverable provider connection exception', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );
})->throws(ProviderConnectionException::class);

test('connection failure fails over to the next provider', function () {
    config([
        'ai.providers.primary' => ['driver' => 'gemini', 'key' => 'test-key'],
        'ai.providers.backup' => ['driver' => 'gemini', 'key' => 'test-key'],
    ]);

    $backup = $this->fakeTextResponse('Recovered on the backup provider');

    $attempts = 0;

    Http::fake(function () use (&$attempts, $backup) {
        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('Connection refused');
        }

        return $backup;
    });

    $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

    expect($response->text)->toBe('Recovered on the backup provider');
});

test('a rejected API key hints at the key', function (int $status, array $body): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, $status)]);

    (new AssistantAgent)->prompt('Hi', provider: 'gemini');
})->with([
    'unauthenticated' => [401, ['error' => [
        'code' => 'authentication',
        'message' => 'Request had invalid authentication credentials.',
    ]]],
    'invalid key' => [400, [['error' => [
        'code' => 400,
        'message' => 'API key not valid. Please pass a valid API key.',
        'status' => 'INVALID_ARGUMENT',
        'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID']],
    ]]]],
    'missing key' => [403, [['error' => [
        'code' => 403,
        'message' => "Method doesn't allow unregistered callers (callers without established identity). Please use API Key or other form of API consumer identity to call this API.",
        'status' => 'PERMISSION_DENIED',
    ]]]],
    'leaked key' => [403, [['error' => [
        'code' => 403,
        'message' => 'Your API key was reported as leaked. Please use another API key.',
        'status' => 'PERMISSION_DENIED',
    ]]]],
])->throws(InvalidApiKeyException::class, 'Missing or invalid API key for AI provider [gemini].');

test('a permission error unrelated to the API key stays a request exception', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([['error' => [
            'code' => 403,
            'message' => 'Your project has been denied access. Please contact support.',
            'status' => 'PERMISSION_DENIED',
        ]]], 403),
    ]);

    expect(fn () => (new AssistantAgent)->prompt('Hi', provider: 'gemini'))
        ->toThrow(fn (RequestException $e) => expect($e)->not->toBeInstanceOf(InvalidApiKeyException::class));
});
