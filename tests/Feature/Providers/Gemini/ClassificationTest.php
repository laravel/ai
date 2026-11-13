<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;

beforeEach(function (): void {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-key',
    ]]);
});

function fakeGeminiDecisionsResponse(): array
{
    return [
        'answers' => [
            'is_urgent' => ['type' => 'noul', 'noul' => 0.96, 'confidence' => 0.96],
            'department' => [
                'type' => 'choice',
                'choice' => 'payments',
                'probabilities' => ['account' => 0.0, 'frontend' => 0.16, 'payments' => 0.84],
                'confidence' => 0.84,
            ],
            'urgency' => [
                'type' => 'score',
                'score' => 1.99,
                'probabilities' => ['0' => 0.0, '1' => 0.01, '2' => 0.99],
                'legend' => ['0' => 'Next release', '1' => 'This week', '2' => '{"label":"Blocking revenue"}'],
                'confidence' => 0.99,
            ],
        ],
        'usage' => ['total_input_tokens' => 312, 'total_cached_tokens' => 256, 'total_output_tokens' => 3, 'total_tokens' => 315],
    ];
}

test('classification posts to the gemini decisions endpoint', function (): void {
    Http::fake(['*' => Http::response(fakeGeminiDecisionsResponse())]);

    Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
        ->questions([
            'is_urgent' => new Boolean('Does this message convey urgency?', [
                'true' => 'The customer is blocked.',
                'false' => ['note' => 'Anything else.'],
            ]),
            'department' => new Choice('Which team should own this ticket?', [
                'account' => 'Login, permissions, or profile issues.',
                'frontend' => ['scope' => 'Rendering'],
                'payments' => null,
            ]),
            'urgency' => new Score('How urgent is this ticket?', [
                'Next release', 'This week', ['label' => 'Blocking revenue'],
            ]),
        ])
        ->classify(provider: 'gemini', model: 'gemini-decision-1');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/decisions'
            && $request->hasHeader('x-goog-api-key', 'test-key')
            && $body['model'] === 'gemini-decision-1'
            && $body['input'] === 'Stripe connect keeps failing, losing sales, help ASAP'
            && ! array_key_exists('state', $body)
            && $body['questions'] === [
                'is_urgent' => [
                    'type' => 'noul',
                    'instructions' => 'Does this message convey urgency?',
                    'criteria' => ['true_description' => 'The customer is blocked.', 'false_description' => '{"note":"Anything else."}'],
                ],
                'department' => [
                    'type' => 'choice',
                    'instructions' => 'Which team should own this ticket?',
                    'criteria' => ['account' => 'Login, permissions, or profile issues.', 'frontend' => '{"scope":"Rendering"}', 'payments' => null],
                ],
                'urgency' => [
                    'type' => 'score',
                    'instructions' => 'How urgent is this ticket?',
                    'criteria' => ['Next release', 'This week', '{"label":"Blocking revenue"}'],
                ],
            ];
    });
});

test('boolean questions without criteria or with one case send only what was given', function (): void {
    Http::fake(['*' => Http::response(fakeGeminiDecisionsResponse())]);

    Classification::of('text')
        ->questions([
            'plain' => new Boolean('Urgent?'),
            'one_case' => new Boolean('Urgent?', ['true' => 'Blocked.']),
        ])
        ->classify(provider: 'gemini');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['questions'] === [
        'plain' => ['type' => 'noul', 'instructions' => 'Urgent?'],
        'one_case' => ['type' => 'noul', 'instructions' => 'Urgent?', 'criteria' => ['true_description' => 'Blocked.']],
    ]);
});

test('structured state is sent as json text', function (): void {
    Http::fake(['*' => Http::response(fakeGeminiDecisionsResponse())]);

    Classification::of(['subject' => 'Broken lamp', 'body' => 'It arrived cracked.'])
        ->question('is_urgent', new Boolean(['rule' => 'Urgent?']))
        ->classify(provider: 'gemini');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['input'] === '{"subject":"Broken lamp","body":"It arrived cracked."}'
            && $body['questions']['is_urgent']['instructions'] === '{"rule":"Urgent?"}';
    });
});

test('decisions response is parsed into typed answers', function (): void {
    Http::fake(['*' => Http::response(fakeGeminiDecisionsResponse())]);

    $response = Classification::of('text')
        ->questions([
            'is_urgent' => new Boolean('Urgent?'),
            'department' => new Choice('Team?', ['account' => null, 'frontend' => null, 'payments' => null]),
            'urgency' => new Score('How urgent?', ['Next release', 'This week', ['label' => 'Blocking revenue']]),
        ])
        ->classify(provider: 'gemini');

    expect($response)->toHaveCount(3)
        ->and($response['is_urgent'])->toBeInstanceOf(BooleanAnswer::class)
        ->and($response['is_urgent']->probability)->toBe(0.96)
        ->and($response['department'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($response['department']->choice)->toBe('payments')
        ->and($response['department']->probabilityOf('frontend'))->toBe(0.16)
        ->and($response['department']->confidence)->toBe(0.84)
        ->and($response['urgency'])->toBeInstanceOf(ScoreAnswer::class)
        ->and($response['urgency']->score)->toBe(1.99)
        ->and($response['urgency']->level())->toBe(2)
        ->and($response['urgency']->label())->toBe(['label' => 'Blocking revenue'])
        ->and($response->usage->inputTokens)->toBe(312)
        ->and($response->usage->cacheReadInputTokens)->toBe(256)
        ->and($response->usage->outputTokens)->toBe(3)
        ->and($response->meta->provider)->toBe('gemini')
        ->and($response->meta->model)->toBe('gemini-decision-1');
});

test('answers of unknown types are skipped', function (): void {
    Http::fake(['*' => Http::response([
        'answers' => [
            'is_urgent' => ['type' => 'noul', 'noul' => 0.2],
            'future' => ['type' => 'ranking', 'ranking' => ['a', 'b']],
        ],
        'usage' => ['total_input_tokens' => 10, 'total_output_tokens' => 1],
    ])]);

    $response = Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');

    expect($response)->toHaveCount(1)
        ->and($response['is_urgent']->probability)->toBe(0.2);
});

test('the raw decisions response is exposed on the classification response', function (): void {
    Http::fake(['*' => Http::response(fakeGeminiDecisionsResponse())]);

    $response = Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');

    expect($response->raw->json('usage.total_tokens'))->toBe(315);
});

test('classification uses the default decisions model when none is specified', function (): void {
    Http::fake(['*' => Http::response(fakeGeminiDecisionsResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'gemini-decision-1');
});

test('the default decisions model can be configured', function (): void {
    config(['ai.providers.gemini.models.classification.default' => 'gemini-decision-2']);

    Http::fake(['*' => Http::response(fakeGeminiDecisionsResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'gemini-decision-2');
});

test('decisions requests use the configured base url', function (): void {
    config(['ai.providers.gemini' => [...config('ai.providers.gemini'), 'url' => 'http://localhost:8080/v1beta/']]);

    Http::fake(['*' => Http::response(fakeGeminiDecisionsResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/v1beta/decisions');
});

test('image attachments are sent as inline image blocks labelled by name', function (): void {
    Http::fake(['*' => Http::response(fakeGeminiDecisionsResponse())]);

    $jpeg = file_get_contents(__DIR__.'/../../../Fixtures/Images/blue.jpg');

    Classification::of('A product photo uploaded by a customer.', [
        'front' => Image::fromPath(__DIR__.'/../../../Fixtures/Images/blue.jpg')->withProviderOptions(fn ($provider) => $provider === Lab::Gemini ? ['resolution' => 'high'] : null),
        'back' => UploadedFile::fake()->createWithContent('back.heic', 'upload-bytes')->mimeType('image/heic'),
    ])
        ->question('damaged', new Boolean('Is the product damaged?'))
        ->classify(provider: 'gemini');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input'] === [
        ['type' => 'text', 'text' => 'A product photo uploaded by a customer.'],
        ['type' => 'text', 'text' => 'front:'],
        ['resolution' => 'high', 'type' => 'image', 'data' => base64_encode($jpeg), 'mime_type' => 'image/jpeg'],
        ['type' => 'text', 'text' => 'back:'],
        ['type' => 'image', 'data' => base64_encode('upload-bytes'), 'mime_type' => 'image/heic'],
    ]);
});

test('uploaded gemini files are sent as image blocks by uri', function (): void {
    Http::fake(['*' => Http::response(fakeGeminiDecisionsResponse())]);

    Classification::of('A product photo uploaded by a customer.', [Image::fromId('files/abc123')])
        ->question('damaged', new Boolean('Is the product damaged?'))
        ->classify(provider: 'gemini');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input'] === [
        ['type' => 'text', 'text' => 'A product photo uploaded by a customer.'],
        ['type' => 'image', 'uri' => 'files/abc123'],
    ]);
});

test('images in unsupported formats are rejected before any request', function (): void {
    Http::fake();

    expect(fn () => Classification::of('Inspect this.', [UploadedFile::fake()->createWithContent('photo.svg', '<svg/>')->mimeType('image/svg+xml')])
        ->question('damaged', new Boolean('Damaged?'))
        ->classify(provider: 'gemini'))->toThrow(InvalidArgumentException::class, 'Gemini decisions only accept JPEG, PNG, GIF, WebP, HEIC, HEIF, BMP, or TIFF images; [image/svg+xml] given.');

    Http::assertNothingSent();
});

test('a rejected decisions request throws a request exception', function (): void {
    Http::fake(['*' => Http::response(['error' => ['code' => 400, 'message' => 'Invalid value at questions', 'status' => 'INVALID_ARGUMENT']], 400)]);

    expect(fn () => Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini'))
        ->toThrow(RequestException::class);
});

test('an overloaded decisions endpoint raises a failover exception', function (): void {
    Http::fake(['*' => Http::response(['error' => ['code' => 503, 'message' => 'The model is overloaded.', 'status' => 'UNAVAILABLE']], 503)]);

    expect(fn () => Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'gemini'))
        ->toThrow(ProviderOverloadedException::class);
});
