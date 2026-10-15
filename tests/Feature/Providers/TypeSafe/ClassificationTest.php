<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\ScoreAnswer;

beforeEach(function (): void {
    config(['ai.providers.typesafe' => [
        ...config('ai.providers.typesafe'),
        'key' => 'test-key',
    ]]);
});

function triageQuestions(): array
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

function fakeTypeSafeResponse(): array
{
    return [
        'model' => 'jev-1.13.0',
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
    Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

    Classification::of('Stripe connect keeps failing, losing sales, help ASAP')
        ->questions(triageQuestions())
        ->classify(provider: 'typesafe', model: 'jev-latest');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.typesafe.ai/v1/systemone'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $body['model'] === 'jev-latest'
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
    Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

    Classification::of('Build finished')
        ->question('passed', new Boolean('Did the build succeed?', [
            'true' => 'exit code 0',
            'false' => 'any non-zero exit code',
        ]))
        ->classify(provider: 'typesafe');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['questions']['passed'] === [
        'type' => 'noul',
        'instructions' => 'Did the build succeed?',
        'criteria' => ['true' => 'exit code 0', 'false' => 'any non-zero exit code'],
    ]);
});

test('structured state is sent as an object', function (): void {
    Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

    Classification::of(['subject' => 'Help', 'messages' => ['first', 'second']])
        ->question('is_urgent', new Boolean('Urgent?'))
        ->classify(provider: 'typesafe');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['state'] === ['subject' => 'Help', 'messages' => ['first', 'second']]);
});

test('classification response is parsed into typed answers', function (): void {
    Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

    $response = Classification::of('text')->questions(triageQuestions())->classify(provider: 'typesafe', model: 'jev-latest');

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
        ->and($response->meta->provider)->toBe('typesafe')
        ->and($response->meta->model)->toBe('jev-1.13.0');
});

test('unknown answer types are skipped', function (): void {
    Http::fake(['*' => Http::response([
        'model' => 'jev-latest',
        'answers' => [
            'is_urgent' => ['type' => 'noul', 'noul' => 0.5],
            'future' => ['type' => 'ranking', 'ranking' => []],
        ],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ])]);

    $response = Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');

    expect($response->collect()->keys()->all())->toBe(['is_urgent']);
});

test('classification uses default model when none specified', function (): void {
    Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'jev-latest');
});

test('classification requests use the configured base url', function (): void {
    config(['ai.providers.typesafe' => [...config('ai.providers.typesafe'), 'url' => 'http://localhost:8080/v1/']]);

    Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/v1/systemone');
});

test('the routed checkpoint is reported as the answering model', function (): void {
    Http::fake(['*' => Http::response([
        ...fakeTypeSafeResponse(),
        'model' => 'laya-rl-agent',
        'routing' => ['model' => 'english', 'reason' => 'English Latin text'],
    ])]);

    $response = Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');

    expect($response->meta->model)->toBe('english');
});

test('provider options may not override the core classification payload', function (): void {
    Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

    Classification::of('text')
        ->question('is_urgent', new Boolean('Urgent?'))
        ->withProviderOptions(['model' => 'hijacked', 'state' => 'hijacked', 'beam_width' => 4])
        ->classify(provider: 'typesafe', model: 'jev-latest');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['model'] === 'jev-latest' && $body['state'] === 'text' && $body['beam_width'] === 4;
    });
});

test('classification throws when the API returns an error', function (): void {
    Http::fake(['*' => Http::response(['detail' => ['error_type' => 'authentication_error']], 401)]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');
})->throws(RequestException::class);

test('classification rate limit response throws rate limited exception', function (): void {
    Http::fake(['api.typesafe.ai/*' => Http::response(['detail' => 'rate limit exceeded'], 429)]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');
})->throws(RateLimitedException::class);

test('classification overloaded response throws provider overloaded exception', function (int $status): void {
    Http::fake(['api.typesafe.ai/*' => Http::response(['detail' => 'overloaded'], $status)]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');
})->with([529, 503])->throws(ProviderOverloadedException::class);

test('image attachments are sent as referenced assets with the images header', function (): void {
    Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

    $jpeg = file_get_contents(__DIR__.'/../../../Fixtures/Images/blue.jpg');
    $png = file_get_contents(__DIR__.'/../../../Fixtures/Images/red.png');

    Classification::of('Inspect this.', [
        Image::fromBase64(base64_encode('base64-bytes'), 'image/jpeg')->withProviderOptions(['custom' => 'value']),
        Image::fromPath(__DIR__.'/../../../Fixtures/Images/blue.jpg'),
        UploadedFile::fake()->createWithContent('upload.png', 'upload-bytes')->mimeType('image/png'),
        Image::fromBase64(base64_encode($png)),
    ])
        ->question('visible_damage', new Boolean('Is the product in the photos damaged?'))
        ->classify(provider: 'typesafe');

    Http::assertSent(function (Request $request) use ($jpeg, $png): bool {
        $body = json_decode($request->body(), true);

        return $request->hasHeader('TypeSafe-Danger-Zone-Images', 'true')
            && $body['state'] === ['Inspect this.', ...array_keys($body['assets'])]
            && array_values($body['assets']) === [
                ['custom' => 'value', 'type' => 'image', 'format' => 'jpg', 'data_base64' => base64_encode('base64-bytes')],
                ['type' => 'image', 'format' => 'jpg', 'data_base64' => base64_encode($jpeg)],
                ['type' => 'image', 'format' => 'png', 'data_base64' => base64_encode('upload-bytes')],
                ['type' => 'image', 'format' => 'png', 'data_base64' => base64_encode($png)],
            ];
    });
});

test('remote and stored images are downloaded and keyed into the state by attachment key', function (): void {
    Http::fake([
        'example.com/*' => Http::response('remote-bytes', 200, ['Content-Type' => 'image/jpeg']),
        '*' => Http::response(fakeTypeSafeResponse()),
    ]);

    Storage::fake('photos')->put('product.png', 'stored-bytes');

    Classification::of('Inspect this.', [
        'front' => Image::fromUrl('https://example.com/product.jpg'),
        'back' => Image::fromStorage('product.png', 'photos'),
    ])
        ->question('visible_damage', new Boolean('Is the product in `front` or `back` damaged?'))
        ->classify(provider: 'typesafe');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.typesafe.ai/v1/systemone'
            && $body['assets'][$body['state']['front']] === ['type' => 'image', 'format' => 'jpg', 'data_base64' => base64_encode('remote-bytes')]
            && $body['assets'][$body['state']['back']] === ['type' => 'image', 'format' => 'png', 'data_base64' => base64_encode('stored-bytes')]
            && $body['state'] === ['text' => 'Inspect this.', 'front' => $body['state']['front'], 'back' => $body['state']['back']];
    });
});

test('unkeyed images are added to structured state under images', function (): void {
    Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

    Classification::of(['subject' => 'Broken lamp'], [Image::fromBase64(base64_encode('photo'), 'image/png')])
        ->question('visible_damage', new Boolean('Is the lamp in `images` damaged?'))
        ->classify(provider: 'typesafe');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['state'] === ['subject' => 'Broken lamp', 'images' => array_keys($body['assets'])];
    });
});

test('requests without attachments do not send assets or the images header', function (): void {
    Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'typesafe');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('TypeSafe-Danger-Zone-Images')
        && ! array_key_exists('assets', json_decode($request->body(), true)));
});

test('attachment keys that repeat state keys are rejected before any request', function (): void {
    Http::fake();

    expect(fn () => Classification::of(['photo' => 'caption'], ['photo' => Image::fromBase64(base64_encode('photo'), 'image/png')])
        ->question('damaged', new Boolean('Damaged?'))
        ->classify(provider: 'typesafe'))->toThrow(InvalidArgumentException::class, 'System One attachment keys must not repeat state keys; [photo] given.');

    Http::assertNothingSent();
});

test('attachments that are not supported images are rejected before any request', function (mixed $attachment, string $message): void {
    Http::fake([
        'example.com/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    expect(fn () => Classification::of('Inspect this.', [$attachment()])
        ->question('visible_damage', new Boolean('Damaged?'))
        ->classify(provider: 'typesafe'))->toThrow(InvalidArgumentException::class, $message);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.typesafe.ai'));
})->with([
    'provider image' => [fn () => Image::fromId('file_123'), 'System One only accepts images with inline content; [Laravel\\Ai\\Files\\ProviderImage] given.'],
    'pdf upload' => [fn () => UploadedFile::fake()->createWithContent('invoice.pdf', 'pdf-bytes')->mimeType('application/pdf'), '[application/pdf] given.'],
    'webp upload' => [fn () => UploadedFile::fake()->createWithContent('photo.webp', 'webp-bytes')->mimeType('image/webp'), '[image/webp] given.'],
    'gif file' => [fn () => Image::fromPath(__DIR__.'/../../../Fixtures/Images/red.png', 'image/gif'), '[image/gif] given.'],
    'html served as a remote image' => [fn () => Image::fromUrl('https://example.com/product.png'), '[text/html] given.'],
]);

test('integer keyed images are appended to the state like a list', function (): void {
    Http::fake(['*' => Http::response(fakeTypeSafeResponse())]);

    Classification::of(['first', 'second'], [1 => Image::fromBase64(base64_encode('a'), 'image/png'), 3 => Image::fromBase64(base64_encode('b'), 'image/png')])
        ->question('visible_damage', new Boolean('Damaged?'))
        ->classify(provider: 'typesafe');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['state'] === ['first', 'second', ...array_keys($body['assets'])];
    });
});
