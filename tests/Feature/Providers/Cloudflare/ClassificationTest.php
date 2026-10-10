<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;
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
    'Clef-omni' => ['@cf/cloudflare/clef-omni', 'clef-omni'],
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
})->with(['clef', 'clef-flash', 'clef-omni']);

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

test('classification supports a configured default model', function (string $selector): void {
    config(['ai.providers.cloudflare.models.classification.default' => '@cf/cloudflare/'.$selector]);

    Http::fake(['*' => Http::response(cloudflareResponse($selector))]);

    Classification::of('text')->question('urgent', new Boolean('Urgent?'))->classify(provider: 'cloudflare');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/@cf/cloudflare/'.$selector)
        && $request['model'] === $selector);
})->with(['clef-flash', 'clef-omni']);

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
})->with(['@cf/cloudflare/clef', '@cf/cloudflare/clef-flash', '@cf/cloudflare/clef-omni']);

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

test('image attachments are sent inline before the state as data urls', function (string $selector): void {
    Http::fake(['*' => Http::response(cloudflareResponse())]);

    $jpeg = file_get_contents(__DIR__.'/../../../Fixtures/Images/blue.jpg');
    $png = file_get_contents(__DIR__.'/../../../Fixtures/Images/red.png');

    Classification::of('Inspect the product in this photo.', [
        Image::fromBase64(base64_encode('base64-bytes'), 'image/jpeg'),
        Image::fromPath(__DIR__.'/../../../Fixtures/Images/blue.jpg'),
        UploadedFile::fake()->createWithContent('upload.webp', 'upload-bytes')->mimeType('image/webp'),
        Image::fromBase64(base64_encode($png)),
    ])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: '@cf/cloudflare/'.$selector);

    Http::assertSent(function (Request $request) use ($jpeg, $png, $selector): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.cloudflare.com/client/v4/accounts/test-account/ai/run/@cf/cloudflare/'.$selector
            && array_keys($body) === ['model', 'images', 'state', 'questions']
            && $body['images'] === [
                'data:image/jpeg;base64,'.base64_encode('base64-bytes'),
                'data:image/jpeg;base64,'.base64_encode($jpeg),
                'data:image/webp;base64,'.base64_encode('upload-bytes'),
                'data:image/png;base64,'.base64_encode($png),
            ];
    });
})->with(['clef', 'clef-flash', 'clef-omni']);

test('remote and stored images are downloaded and sent as data urls', function (): void {
    Http::fake([
        'example.com/*' => Http::response('remote-bytes', 200, ['Content-Type' => 'image/png']),
        'api.cloudflare.com/*' => Http::response(cloudflareResponse()),
    ]);

    Storage::fake('photos')->put('product.jpg', 'stored-bytes');

    Classification::of('Inspect this.', [
        'front' => Image::fromUrl('https://example.com/product.png'),
        'back' => Image::fromStorage('product.jpg', 'photos'),
    ])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.cloudflare.com')
        && json_decode($request->body(), true)['images'] === [
            'data:image/png;base64,'.base64_encode('remote-bytes'),
            'data:image/jpeg;base64,'.base64_encode('stored-bytes'),
        ]);
});

test('attachments that are not supported images are rejected before any request', function (mixed $attachment, string $message): void {
    Http::fake([
        'example.com/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    expect(fn (): ClassificationResponse => Classification::of('Inspect this.', [$attachment()])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare'))->toThrow(InvalidArgumentException::class, $message);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.cloudflare.com'));
})->with([
    'provider image' => [fn (): File => Image::fromId('file_123'), 'Cloudflare Clef only accepts images with inline content; [Laravel\\Ai\\Files\\ProviderImage] given.'],
    'pdf upload' => [fn () => UploadedFile::fake()->createWithContent('invoice.pdf', 'pdf-bytes')->mimeType('application/pdf'), '[application/pdf] given.'],
    'gif upload' => [fn () => UploadedFile::fake()->createWithContent('animation.gif', 'gif-bytes')->mimeType('image/gif'), '[image/gif] given.'],
    'gif file' => [fn (): File => Image::fromBase64(base64_encode('gif-bytes'), 'image/gif'), '[image/gif] given.'],
    'heic upload' => [fn () => UploadedFile::fake()->createWithContent('photo.heic', 'heic-bytes')->mimeType('image/heic'), '[image/heic] given.'],
    'heic file' => [fn (): File => Image::fromPath(__DIR__.'/../../../Fixtures/Images/red.png', 'image/heic'), '[image/heic] given.'],
    'html served as a remote image' => [fn (): File => Image::fromUrl('https://example.com/product.png'), '[text/html] given.'],
]);

test('more than 4 image attachments are rejected before any request', function (): void {
    Http::fake();

    $images = array_map(fn (): File => Image::fromBase64(base64_encode('bytes'), 'image/png'), range(1, 5));

    expect(fn (): ClassificationResponse => Classification::of('Inspect these.', $images)
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare'))->toThrow(InvalidArgumentException::class, 'Cloudflare Clef accepts a maximum of 4 image attachments.');

    Http::assertNothingSent();
});

test('attachments fail loudly instead of failing over to a provider that cannot classify them', function (): void {
    config(['ai.providers.typesafe.key' => 'test-key']);

    Http::fake(['api.cloudflare.com/*' => Http::response([], 503)]);

    expect(fn (): ClassificationResponse => Classification::of('Inspect this.', [Image::fromBase64(base64_encode('photo'), 'image/png')])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: ['cloudflare', 'typesafe']))->toThrow(LogicException::class, 'Provider [typesafe] does not support classification attachments.');

    Http::assertSentCount(1);
});

test('Cloudflare can be the failover destination with attachments', function (): void {
    config(['ai.providers.openai.key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response([], 503),
        'api.cloudflare.com/*' => Http::response(cloudflareResponse()),
    ]);

    $response = Classification::of('Inspect this.', [Image::fromBase64(base64_encode('photo'), 'image/png')])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: ['openai', 'cloudflare']);

    expect($response->meta->provider)->toBe('cloudflare')->and($response['urgent']->probability)->toBe(0.92);
    Http::assertSentCount(2);
});

test('Omni sends mixed attachments in separate modality arrays', function (): void {
    Event::fake([Classifying::class, Classified::class]);
    Http::fake(['*' => Http::response(cloudflareResponse('clef-omni'))]);

    $attachments = [
        'video' => Video::fromBase64(base64_encode('video-bytes'), 'video/mp4'),
        'photo' => Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
        'audio' => Audio::fromBase64(base64_encode('audio-bytes'), 'audio/mpeg'),
    ];

    $response = Classification::of('Inspect the product.', $attachments)
        ->questions(cloudflareQuestions())
        ->classify(provider: Lab::Cloudflare, model: '@cf/cloudflare/clef-omni');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['model'] === 'clef-omni'
        && $request['images'] === ['data:image/png;base64,'.base64_encode('image-bytes')]
        && $request['audio'] === ['data:audio/mpeg;base64,'.base64_encode('audio-bytes')]
        && $request['videos'] === ['data:video/mp4;base64,'.base64_encode('video-bytes')]);
    expect($response['urgent']->probability)->toBe(0.92)
        ->and($response->meta->model)->toBe('clef-omni');
    Event::assertDispatched(Classifying::class, fn (Classifying $event): bool => $event->prompt->attachments === $attachments);
    Event::assertDispatched(Classified::class, fn (Classified $event): bool => $event->response === $response);
});

test('Omni reads audio and video from SDK file sources', function (string $type, string $class, string $mime, string $extension, string $source): void {
    Http::fake([
        'example.com/*' => Http::response('media-bytes', 200, ['Content-Type' => $mime]),
        'api.cloudflare.com/*' => Http::response(cloudflareResponse('clef-omni')),
    ]);
    Storage::fake('media')->put('clip.'.$extension, 'media-bytes');

    $attachment = match ($source) {
        'base64' => $class::fromBase64(base64_encode('media-bytes'), $mime),
        'path' => $class::fromPath(Storage::disk('media')->path('clip.'.$extension), $mime),
        'storage' => $class::fromStorage('clip.'.$extension, 'media')->withMimeType($mime),
        'url' => $class::fromUrl('https://example.com/clip.'.$extension),
        'upload' => UploadedFile::fake()->createWithContent('clip.'.$extension, 'media-bytes')->mimeType($mime),
    };

    Classification::of('Inspect this.', [$attachment])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: '@cf/cloudflare/clef-omni');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.cloudflare.com')
        && $request[$type] === ['data:'.$mime.';base64,'.base64_encode('media-bytes')]
        && array_keys($request->data()) === ['model', $type, 'state', 'questions']);
})->with([
    'MP3' => ['audio', Audio::class, 'audio/mpeg', 'mp3'],
    'WAV' => ['audio', Audio::class, 'audio/wav', 'wav'],
    'MP4' => ['videos', Video::class, 'video/mp4', 'mp4'],
    'WebM' => ['videos', Video::class, 'video/webm', 'webm'],
])->with(['base64', 'path', 'storage', 'url', 'upload']);

test('Omni detects audio MIME types and normalizes common aliases', function (?string $mime): void {
    Http::fake(['*' => Http::response(cloudflareResponse('clef-omni'))]);
    $audio = file_get_contents(__DIR__.'/../../../Fixtures/audio.mp3');

    Classification::of('Listen to this.', [Audio::fromBase64(base64_encode($audio), $mime)])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: '@cf/cloudflare/clef-omni');

    Http::assertSent(fn (Request $request): bool => $request['audio'] === ['data:audio/mpeg;base64,'.base64_encode($audio)]);
})->with([null, 'audio/mp3', 'audio/mpeg']);

test('Omni normalizes WAV MIME aliases', function (string $mime): void {
    Http::fake(['*' => Http::response(cloudflareResponse('clef-omni'))]);

    Classification::of('Listen to this.', [Audio::fromBase64(base64_encode('wav-bytes'), $mime)])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: '@cf/cloudflare/clef-omni');

    Http::assertSent(fn (Request $request): bool => $request['audio'] === ['data:audio/wav;base64,'.base64_encode('wav-bytes')]);
})->with(['audio/x-wav', 'audio/wave', 'audio/vnd.wave']);

test('audio and video require Omni before remote media is downloaded', function (string $model, string $class): void {
    Http::fake();

    expect(fn (): ClassificationResponse => Classification::of('Inspect this.', [$class::fromUrl('https://example.com/media')])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: $model))
        ->toThrow(InvalidArgumentException::class, 'Cloudflare audio and video classification requires the Clef Omni model.');

    Http::assertNothingSent();
})->with(['@cf/cloudflare/clef', '@cf/cloudflare/clef-flash'])->with([Audio::class, Video::class]);

test('Omni rejects unsupported attachment types and formats', function (Closure $attachment): void {
    Http::fake();

    expect(fn (): ClassificationResponse => Classification::of('Inspect this.', [$attachment()])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: '@cf/cloudflare/clef-omni'))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'document' => [fn (): File => Document::fromBase64(base64_encode('pdf'), 'application/pdf')],
    'provider image' => [fn (): File => Image::fromId('file_123')],
    'OGG audio' => [fn (): File => Audio::fromBase64(base64_encode('ogg'), 'audio/ogg')],
    'QuickTime video' => [fn (): File => Video::fromBase64(base64_encode('mov'), 'video/quicktime')],
    'video declared as audio' => [fn (): File => Audio::fromBase64(base64_encode('video'), 'video/mp4')],
    'audio declared as video' => [fn (): File => Video::fromBase64(base64_encode('audio'), 'audio/mpeg')],
    'OGG upload' => [fn () => UploadedFile::fake()->createWithContent('clip.ogg', 'ogg')->mimeType('audio/ogg')],
]);

test('Omni enforces attachment counts before downloading remote media', function (string $class, int $count, string $message): void {
    Http::fake();

    expect(fn (): ClassificationResponse => Classification::of('Inspect this.', array_fill(0, $count, $class::fromUrl('https://example.com/media')))
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: '@cf/cloudflare/clef-omni'))->toThrow(InvalidArgumentException::class, $message);

    Http::assertNothingSent();
})->with([
    'images' => [Image::class, 5, 'maximum of 4 image attachments'],
    'audio' => [Audio::class, 5, 'maximum of 4 audio attachments'],
    'videos' => [Video::class, 3, 'maximum of 2 video attachments'],
]);

test('Omni applies attachment count limits per modality', function (): void {
    Http::fake(['*' => Http::response(cloudflareResponse('clef-omni'))]);

    Classification::of('Inspect this.', [
        ...array_fill(0, 4, Image::fromBase64(base64_encode('photo'), 'image/png')),
        ...array_fill(0, 4, Audio::fromBase64(base64_encode('audio'), 'audio/wav')),
        ...array_fill(0, 2, Video::fromBase64(base64_encode('video'), 'video/mp4')),
    ])->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: '@cf/cloudflare/clef-omni');

    Http::assertSent(fn (Request $request): bool => count($request['images']) === 4
        && count($request['audio']) === 4 && count($request['videos']) === 2);
});

test('Omni rejects oversized attachments', function (string $class, string $mime, int $maxMiB): void {
    Http::fake();
    Storage::fake('media')->put('large-file', str_repeat('x', $maxMiB * 1024 * 1024 + 1));

    expect(fn (): ClassificationResponse => Classification::of('Inspect this.', [$class::fromStorage('large-file', 'media')->withMimeType($mime)])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: '@cf/cloudflare/clef-omni'))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([
    'image' => [Image::class, 'image/png', 4],
    'audio' => [Audio::class, 'audio/wav', 8],
    'video' => [Video::class, 'video/mp4', 16],
]);

test('Omni enforces total decoded attachment sizes', function (bool $images): void {
    Http::fake();
    Storage::fake('media');
    Storage::disk('media')->put('first-file', str_repeat('x', ($images ? 3 : 8) * 1024 * 1024));

    if (! $images) {
        Storage::disk('media')->put('second-file', str_repeat('x', 8 * 1024 * 1024 + 1));
    }

    $attachments = $images
        ? array_fill(0, 3, Image::fromStorage('first-file', 'media')->withMimeType('image/png'))
        : [
            Audio::fromStorage('first-file', 'media')->withMimeType('audio/wav'),
            Video::fromStorage('second-file', 'media')->withMimeType('video/mp4'),
        ];

    expect(fn (): ClassificationResponse => Classification::of('Inspect this.', $attachments)
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: '@cf/cloudflare/clef-omni'))
        ->toThrow(InvalidArgumentException::class, $images ? 'image attachments may not exceed 8 MiB in total' : 'audio and video attachments may not exceed 16 MiB in total');

    Http::assertNothingSent();
})->with([true, false]);

test('Omni fakes preserve mixed attachments without downloading media', function (): void {
    Http::fake();
    Classification::fake([['urgent' => new BooleanAnswer(0.9)]]);
    $attachments = [
        Image::fromUrl('https://example.com/photo.jpg'),
        Audio::fromUrl('https://example.com/clip.mp3'),
        Video::fromUrl('https://example.com/clip.mp4'),
    ];

    Classification::of('Inspect this.', $attachments)->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: 'cloudflare', model: '@cf/cloudflare/clef-omni');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->attachments === $attachments
        && $prompt->model === '@cf/cloudflare/clef-omni');
    Http::assertNothingSent();
});

test('Omni failover preserves media and rejects incompatible destinations', function (string $class): void {
    config(['ai.providers.openai.key' => 'test-key']);
    Http::fake(['api.cloudflare.com/*' => Http::response([], 503)]);

    expect(fn (): ClassificationResponse => Classification::of('Inspect this.', [$class::fromBase64(base64_encode('media'), $class === Audio::class ? 'audio/wav' : 'video/mp4')])
        ->question('urgent', new Boolean('Urgent?'))
        ->classify(provider: ['cloudflare' => '@cf/cloudflare/clef-omni', 'openai']))
        ->toThrow(InvalidArgumentException::class, 'OpenAI decisions only accept images with inline content');

    Http::assertSentCount(1);
})->with([Audio::class, Video::class]);
