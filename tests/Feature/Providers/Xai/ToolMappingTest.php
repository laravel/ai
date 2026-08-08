<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\WebSearch;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\NamedTool;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

test('tool with parameters includes correct schema', function (): void {
    Http::fake([
        '*' => fakeXaiToolMappingResponse('42'),
    ]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'xai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

        return $tool['parameters']['type'] === 'object'
            && array_key_exists('min', $tool['parameters']['properties'])
            && array_key_exists('max', $tool['parameters']['properties'])
            && in_array('min', $tool['parameters']['required'])
            && in_array('max', $tool['parameters']['required'])
            && $tool['parameters']['additionalProperties'] === false;
    });
});

test('tool with empty schema includes parameters', function (): void {
    Http::fake([
        '*' => fakeXaiToolMappingResponse('72019'),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a random number', provider: 'xai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

        return array_key_exists('parameters', $tool)
            && $tool['parameters']['type'] === 'object'
            && $tool['parameters']['properties'] === []
            && $tool['parameters']['required'] === []
            && $tool['parameters']['additionalProperties'] === false;
    });
});

test('tool with a name() method emits the declared name', function (): void {
    Http::fake(['*' => fakeXaiToolMappingResponse('ok')]);

    agent(tools: [new NamedTool('my_custom_tool')])->prompt('Hi', provider: 'xai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $names = collect(data_get($body, 'tools'))->pluck('name')->all();

        return in_array('my_custom_tool', $names, true);
    });
});

test('tool parameters are not wrapped in schema definition', function (): void {
    Http::fake([
        '*' => fakeXaiToolMappingResponse('done'),
    ]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a random number', provider: 'xai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'function');

        return ! array_key_exists('schema_definition', $tool['parameters']['properties'] ?? [])
            && ! in_array('schema_definition', $tool['parameters']['required'] ?? []);
    });
});

test('web search tool sends type web_search', function (): void {
    Http::fake(['*' => fakeXaiToolMappingResponse('result')]);

    agent(tools: [new WebSearch])->prompt('Search the web', provider: 'xai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return $tool !== null;
    });
});

test('web search tool sends allowed_domains', function (): void {
    Http::fake(['*' => fakeXaiToolMappingResponse('result')]);

    agent(tools: [(new WebSearch)->allow(['example.com', 'docs.example.com'])])
        ->prompt('Search', provider: 'xai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return data_get($tool, 'allowed_domains') === ['example.com', 'docs.example.com'];
    });
});

test('web search tool forwards xai provider options into the tool payload', function (): void {
    Http::fake(['*' => fakeXaiToolMappingResponse('result')]);

    agent(tools: [
        (new WebSearch)->withProviderOptions([
            'excluded_domains' => ['spam.example.com'],
            'enable_image_understanding' => true,
        ]),
    ])->prompt('Search', provider: 'xai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('type', 'web_search');

        return data_get($tool, 'excluded_domains') === ['spam.example.com']
            && data_get($tool, 'enable_image_understanding') === true;
    });
});

test('web search response citations are parsed from url_citation annotations', function (): void {
    Http::fake(['*' => Http::response([
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4.3',
        'output' => [
            [
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => 'xAI builds Grok. [[1]](https://x.ai)',
                        'annotations' => [
                            [
                                'type' => 'url_citation',
                                'url' => 'https://x.ai',
                                'title' => '1',
                                'start_index' => 17,
                                'end_index' => 37,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ])]);

    $response = agent(tools: [new WebSearch])->prompt('What is xAI?', provider: 'xai');

    expect($response->meta->citations)->toHaveCount(1)
        ->and($response->meta->citations[0]->url)->toBe('https://x.ai')
        ->and($response->meta->citations[0]->startIndex)->toBe(17);
});

function fakeXaiToolMappingResponse(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'grok-4-1-fast-reasoning',
        'output' => [
            [
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [
                    ['type' => 'output_text', 'text' => $text],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 1,
            'output_tokens' => 1,
        ],
    ]);
}
