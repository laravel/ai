<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Classification\Score;
use Laravel\Ai\Files\Image;

beforeEach(function (): void {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

function fakeOpenAiDecisionsResponse(): array
{
    return [
        'model' => 'gpt-6-luna',
        'answers' => [
            ['type' => 'predicate', 'name' => 'is_urgent', 'probability' => 0.92],
            [
                'type' => 'choice',
                'name' => 'department',
                'choice' => 'billing',
                'probabilities' => [
                    ['value' => 'billing', 'probability' => 0.95],
                    ['value' => 'shipping', 'probability' => 0.05],
                ],
                'confidence' => 0.93,
            ],
            [
                'type' => 'score',
                'name' => 'severity',
                'score' => 1.1,
                'probabilities' => [
                    ['value' => 0, 'label' => 'Cosmetic', 'probability' => 0.1],
                    ['value' => 1, 'label' => 'Workaround available', 'probability' => 0.7],
                    ['value' => 2, 'label' => 'Fully blocked', 'probability' => 0.2],
                ],
                'confidence' => 0.55,
            ],
            ['type' => 'refusal', 'name' => 'refused'],
        ],
        'usage' => ['input_tokens' => 120, 'output_tokens' => 0],
    ];
}

test('classification posts questions to the decisions endpoint in the openai wire format', function (): void {
    Http::fake(['*' => Http::response(fakeOpenAiDecisionsResponse())]);

    Classification::of('I was charged twice for my order.')
        ->questions([
            'is_urgent' => new Boolean('Is this urgent?', ['true' => 'Money was lost.', 'false' => 'A question only.']),
            'department' => new Choice('Which department?', [
                'billing' => 'Payments and refunds.',
                'shipping' => null,
            ]),
            'severity' => new Score(['ask' => 'How severe?'], ['Cosmetic', 'Workaround available', 'Fully blocked']),
        ])
        ->classify(provider: 'openai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.openai.com/v1/decisions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $body === [
                'model' => 'gpt-6-luna',
                'input' => 'I was charged twice for my order.',
                'questions' => [
                    [
                        'type' => 'predicate',
                        'name' => 'is_urgent',
                        'instructions' => "Is this urgent?\n\nTrue: Money was lost.\n\nFalse: A question only.",
                    ],
                    [
                        'type' => 'choice',
                        'name' => 'department',
                        'instructions' => 'Which department?',
                        'choices' => [
                            ['value' => 'billing', 'description' => 'Payments and refunds.'],
                            ['value' => 'shipping'],
                        ],
                    ],
                    [
                        'type' => 'score',
                        'name' => 'severity',
                        'instructions' => '{"ask":"How severe?"}',
                        'levels' => [
                            ['label' => 'Cosmetic'],
                            ['label' => 'Workaround available'],
                            ['label' => 'Fully blocked'],
                        ],
                    ],
                ],
            ];
    });
});

test('structured state is sent as json text', function (): void {
    Http::fake(['*' => Http::response(fakeOpenAiDecisionsResponse())]);

    Classification::of(['subject' => 'Refund', 'body' => 'Charged twice'])
        ->question('is_urgent', new Boolean('Is this urgent?'))
        ->classify(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input'] === '{"subject":"Refund","body":"Charged twice"}');
});

test('image attachments are sent inline with the state as a user message', function (): void {
    Http::fake(['*' => Http::response(fakeOpenAiDecisionsResponse())]);

    $path = tempnam(sys_get_temp_dir(), 'ai');
    file_put_contents($path, 'local-bytes');

    Classification::of('Inspect the product in this photo.', [
        Image::fromBase64(base64_encode('base64-bytes'), 'image/jpeg'),
        Image::fromPath($path, 'image/png'),
        UploadedFile::fake()->createWithContent('upload.webp', 'upload-bytes')->mimeType('image/webp'),
    ])
        ->question('visible_damage', new Boolean('Does the product have visible damage?'))
        ->classify(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['input'] === [[
        'role' => 'user',
        'content' => [
            ['type' => 'input_text', 'text' => 'Inspect the product in this photo.'],
            ['type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,'.base64_encode('base64-bytes')],
            ['type' => 'input_image', 'image_url' => 'data:image/png;base64,'.base64_encode('local-bytes')],
            ['type' => 'input_image', 'image_url' => 'data:image/webp;base64,'.base64_encode('upload-bytes')],
        ],
    ]]);
});

test('remote and stored images are downloaded and sent inline', function (): void {
    Http::fake([
        'example.com/*' => Http::response('remote-bytes', 200, ['Content-Type' => 'image/gif']),
        '*' => Http::response(fakeOpenAiDecisionsResponse()),
    ]);

    Storage::fake('photos')->put('product.png', 'stored-bytes');

    Classification::of('Inspect this.', [
        Image::fromUrl('https://example.com/product.gif'),
        Image::fromStorage('product.png', 'photos'),
    ])
        ->question('visible_damage', new Boolean('Damaged?'))
        ->classify(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/decisions'
        && array_slice(json_decode($request->body(), true)['input'][0]['content'], 1) === [
            ['type' => 'input_image', 'image_url' => 'data:image/gif;base64,'.base64_encode('remote-bytes')],
            ['type' => 'input_image', 'image_url' => 'data:image/png;base64,'.base64_encode('stored-bytes')],
        ]);
});

test('images that cannot be sent inline are rejected before any request', function (): void {
    Http::fake();

    expect(fn () => Classification::of('Inspect this.', [Image::fromId('file_123')])
        ->question('visible_damage', new Boolean('Damaged?'))
        ->classify(provider: 'openai'))->toThrow(InvalidArgumentException::class);

    expect(fn () => Classification::of('Inspect this.', [UploadedFile::fake()->createWithContent('invoice.pdf', 'pdf-bytes')->mimeType('application/pdf')])
        ->question('visible_damage', new Boolean('Damaged?'))
        ->classify(provider: 'openai'))->toThrow(InvalidArgumentException::class);

    expect(fn () => Classification::of('Inspect this.', [UploadedFile::fake()->createWithContent('photo.heic', 'heic-bytes')->mimeType('image/heic')])
        ->question('visible_damage', new Boolean('Damaged?'))
        ->classify(provider: 'openai'))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

test('decisions answers are matched to questions by name', function (): void {
    Http::fake(['*' => Http::response(fakeOpenAiDecisionsResponse())]);

    $response = Classification::of('text')
        ->questions([
            'is_urgent' => new Boolean('Urgent?'),
            'department' => new Choice('Department?', ['billing' => null, 'shipping' => null]),
            'severity' => new Score('Severity?', ['Cosmetic issue', 'Has workaround', 'Blocked']),
            'refused' => new Boolean('Refused?'),
        ])
        ->classify(provider: 'openai');

    expect($response)->toHaveCount(3)
        ->and($response['is_urgent']->probability)->toBe(0.92)
        ->and($response['department']->choice)->toBe('billing')
        ->and($response['department']->probabilities)->toBe(['billing' => 0.95, 'shipping' => 0.05])
        ->and($response['department']->confidence)->toBe(0.93)
        ->and($response['severity']->score)->toBe(1.1)
        ->and($response['severity']->probabilities)->toBe([0 => 0.1, 1 => 0.7, 2 => 0.2])
        ->and($response['severity']->label())->toBe('Has workaround')
        ->and($response['severity']->confidence)->toBe(0.55)
        ->and(isset($response['refused']))->toBeFalse()
        ->and($response->usage->inputTokens)->toBe(120)
        ->and($response->meta->provider)->toBe('openai')
        ->and($response->meta->model)->toBe('gpt-6-luna');
});

test('classification uses the configured classification model', function (): void {
    config(['ai.providers.openai.models.classification.default' => 'gpt-6-luna-preview']);

    Http::fake(['*' => Http::response(fakeOpenAiDecisionsResponse())]);

    Classification::of('text')->question('is_urgent', new Boolean('Urgent?'))->classify(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'gpt-6-luna-preview');
});
