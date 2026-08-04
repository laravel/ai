<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;

beforeEach(function (): void {
    config(['ai.providers.gemini' => [
        ...config('ai.providers.gemini'),
        'key' => 'test-key',
    ]]);
});

function fakeGeminiEmbeddingsResponse(): PromiseInterface
{
    return Http::response([
        'embeddings' => [
            ['values' => [0.1, 0.2, 0.3]],
        ],
        'usageMetadata' => [
            'promptTokenCount' => 10,
        ],
    ]);
}

function fakeGeminiBatchEmbeddingsResponse(array $embeddings = [[0.1, 0.2, 0.3]], int $tokens = 10): PromiseInterface
{
    return Http::response([
        'embeddings' => array_map(fn (array $values): array => ['values' => $values], $embeddings),
        'usageMetadata' => [
            'promptTokenCount' => $tokens,
        ],
    ]);
}

test('embeddings request posts to batchEmbedContents endpoint', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello world'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'models/gemini-embedding-001:batchEmbedContents'));
});

test('embeddings request wraps each input in a request object', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello world'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $firstRequest = data_get($body, 'requests.0');

        return $firstRequest['model'] === 'models/gemini-embedding-001'
            && $firstRequest['content']['parts'][0]['text'] === 'Hello world'
            && ! array_key_exists('outputDimensionality', $firstRequest);
    });
});

test('embeddings response is correctly parsed', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    $response = Embeddings::for(['Hello world'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    expect($response->embeddings)->toHaveCount(1)
        ->and($response->embeddings[0])->toBe([0.1, 0.2, 0.3])
        ->and($response->tokens)->toBe(10)
        ->and($response->meta->provider)->toBe('gemini')
        ->and($response->meta->model)->toBe('gemini-embedding-001');
});

test('multiple inputs are sent as separate requests in the batch', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'embeddings' => [
                ['values' => [0.1, 0.2, 0.3]],
                ['values' => [0.4, 0.5, 0.6]],
            ],
            'usageMetadata' => ['promptTokenCount' => 20],
        ]),
    ]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return count($body['requests']) === 2
            && $body['requests'][0]['content']['parts'][0]['text'] === 'Hello'
            && $body['requests'][1]['content']['parts'][0]['text'] === 'World';
    });

    expect($response->embeddings)->toHaveCount(2);
});

test('gemini embedding 2 inputs are sent in a single batch request', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], 30),
    ]);

    $response = Embeddings::for(['Hello', 'World'])->generate(provider: 'gemini', model: 'gemini-embedding-2');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'models/gemini-embedding-2:batchEmbedContents')
        && data_get($request->data(), 'requests.0.content.parts.0.text') === 'Hello'
        && data_get($request->data(), 'requests.1.content.parts.0.text') === 'World');

    expect($response->embeddings)->toBe([
        [0.1, 0.2, 0.3],
        [0.4, 0.5, 0.6],
    ])->and($response->tokens)->toBe(30);
});

test('explicit dimensions are sent as outputDimensionality', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello world'])->dimensions(768)->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'requests.0.outputDimensionality') === 768;
    });
});

test('multimodal embeddings require an embedding 2 model', function (): void {
    Embeddings::for([
        Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-001');
})->throws(InvalidArgumentException::class, 'gemini-embedding-2');

test('base64 image embeddings are sent as inline data', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
    ]);

    Embeddings::for([
        Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return str_contains($request->url(), 'models/gemini-embedding-2:batchEmbedContents')
            && data_get($body, 'requests.0.content.parts.0.inlineData.mimeType') === 'image/png'
            && data_get($body, 'requests.0.content.parts.0.inlineData.data') === base64_encode('image-bytes');
    });
});

test('text mixed with media inputs is sent in a single batch request', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
    ]);

    Embeddings::for([
        'Hello world',
        Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'models/gemini-embedding-2:batchEmbedContents')
        && data_get($request->data(), 'requests.0.content.parts.0.text') === 'Hello world'
        && data_get($request->data(), 'requests.1.content.parts.0.inlineData.data') === base64_encode('image-bytes'));
});

test('base64 audio embeddings are sent as inline data', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
    ]);

    Embeddings::for([
        Audio::fromBase64(base64_encode('audio-bytes'), 'audio/mpeg'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

    Http::assertSent(fn (Request $request) => data_get($request->data(), 'requests.0.content.parts.0.inlineData.mimeType') === 'audio/mpeg'
        && data_get($request->data(), 'requests.0.content.parts.0.inlineData.data') === base64_encode('audio-bytes'));
});

test('base64 document embeddings are sent as inline data', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
    ]);

    Embeddings::for([
        Document::fromBase64(base64_encode('document-bytes'), 'application/pdf'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

    Http::assertSent(fn (Request $request) => data_get($request->data(), 'requests.0.content.parts.0.inlineData.mimeType') === 'application/pdf'
        && data_get($request->data(), 'requests.0.content.parts.0.inlineData.data') === base64_encode('document-bytes'));
});

test('local file embeddings are sent as inline data', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
    ]);

    $path = tempnam(sys_get_temp_dir(), 'gemini-embedding-test');
    file_put_contents($path, 'image-bytes');

    try {
        Embeddings::for([
            Image::fromPath($path, 'image/png'),
        ])->generate(provider: 'gemini', model: 'gemini-embedding-2');
    } finally {
        unlink($path);
    }

    Http::assertSent(fn (Request $request) => data_get($request->data(), 'requests.0.content.parts.0.inlineData.data') === base64_encode('image-bytes'));
});

test('provider file embeddings are sent as file data', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
    ]);

    Embeddings::for([
        Image::fromId('files/file_123'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'models/gemini-embedding-2:batchEmbedContents')
        && data_get($request->data(), 'requests.0.content.parts.0.fileData.fileUri') === 'files/file_123');
});

test('multimodal embeddings accept canonical model names', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
    ]);

    Embeddings::for([
        Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
    ])->generate(provider: 'gemini', model: 'models/gemini-embedding-2');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), 'models/gemini-embedding-2:batchEmbedContents')
        && ! str_contains($request->url(), 'models/models/'));
});

test('multimodal embeddings still accept preview model', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
    ]);

    Embeddings::for([
        Image::fromBase64(base64_encode('image-bytes'), 'image/png'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-2-preview');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), 'models/gemini-embedding-2-preview:batchEmbedContents'));
});

test('remote video embeddings preserve file uris', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiBatchEmbeddingsResponse(),
    ]);

    Embeddings::for([
        Video::fromUrl('https://www.youtube.com/watch?v=demo'),
    ])->generate(provider: 'gemini', model: 'gemini-embedding-2');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && data_get($request->data(), 'requests.0.content.parts.0.fileData.fileUri') === 'https://www.youtube.com/watch?v=demo');
});

test('missing embeddings key in response returns empty array', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'usageMetadata' => ['promptTokenCount' => 5],
        ]),
    ]);

    $response = Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    expect($response->embeddings)->toBe([]);
});

test('missing usageMetadata in response returns zero tokens', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'embeddings' => [['values' => [0.1, 0.2, 0.3]]],
        ]),
    ]);

    $response = Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    expect($response->tokens)->toBe(0);
});

test('rate limit response throws rate limited exception', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');
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

    Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');
})->throws(ProviderOverloadedException::class);

test('http error response throws request exception', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');
})->throws(RequestException::class);

test('request sends x-goog-api-key header', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(fn (Request $request) => $request->hasHeader('x-goog-api-key', 'test-key'));
});

test('embeddings request merges provider options into each per-input request', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello', 'World'])
        ->withProviderOptions(['taskType' => 'RETRIEVAL_QUERY', 'title' => 'doc'])
        ->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        if (array_key_exists('taskType', $body) || array_key_exists('title', $body)) {
            return false;
        }

        foreach ($body['requests'] as $req) {
            if (($req['taskType'] ?? null) !== 'RETRIEVAL_QUERY' || ($req['title'] ?? null) !== 'doc') {
                return false;
            }

            if (! isset($req['model'], $req['content'])) {
                return false;
            }
        }

        return count($body['requests']) === 2;
    });
});

test('gemini provider options cannot override framework controlled per-request keys', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => fakeGeminiEmbeddingsResponse(),
    ]);

    Embeddings::for(['Hello'])
        ->dimensions(3072)
        ->withProviderOptions([
            'model' => 'hijacked',
            'content' => ['hijacked'],
            'outputDimensionality' => 1,
            'output_dimensionality' => 1,
        ])
        ->generate(provider: 'gemini', model: 'gemini-embedding-001');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $req = $body['requests'][0];

        return $req['model'] === 'models/gemini-embedding-001'
            && $req['content'] === ['parts' => [['text' => 'Hello']]]
            && $req['outputDimensionality'] === 3072
            && ! array_key_exists('output_dimensionality', $req);
    });
});
