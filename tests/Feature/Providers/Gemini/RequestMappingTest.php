<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\NestedStructuredAgent;
use Tests\Fixtures\Agents\NullableStructuredAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

describe('request structure', function () {
    test('request includes model in url and contents', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('Laravel is great'),
        ]);

        (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'gemini',
            model: 'gemini-3.5-flash',
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'models/gemini-3.5-flash:generateContent')
                && $request->data()['contents'][0]['role'] === 'user'
                && $request->data()['contents'][0]['parts'][0]['text'] === 'What is Laravel?';
        });
    });

    test('system instructions are sent as system instruction field', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['system_instruction'])
                && isset($body['system_instruction']['parts'][0]['text'])
                && str_contains($body['system_instruction']['parts'][0]['text'], 'helpful');
        });
    });

    test('request without tools excludes tool fields', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['tools'])
                && ! isset($body['tool_config']);
        });
    });

    test('request sends api key header', function () {
        config(['ai.providers.gemini' => [
            ...config('ai.providers.gemini'),
            'key' => 'test-key',
        ]]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-goog-api-key', 'test-key');
        });
    });

    test('request omits the api key header when no key is configured', function () {
        config(['ai.providers.gemini' => [
            ...config('ai.providers.gemini'),
            'key' => null,
        ]]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            return ! $request->hasHeader('x-goog-api-key');
        });
    });

    test('tool_config is omitted to rely on Gemini default AUTO mode', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['tools'])
                && ! isset($body['tool_config']);
        });
    });

    test('function call id is extracted from response', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_abc123'),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $steps = $response->steps;

        expect($steps)->not->toBeEmpty();

        $toolCall = $steps->first()->toolCalls[0] ?? null;

        expect($toolCall)->not->toBeNull()
            ->and($toolCall->id)->toBe('call_abc123');
    });
});

describe('structured output', function () {
    test('structured output uses response json schema', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse(['symbol' => 'Fe']),
        ]);

        (new StructuredAgent)->prompt(
            'What is the symbol for Iron?',
            provider: 'gemini',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            $config = $body['generationConfig'] ?? [];

            return ($config['response_mime_type'] ?? '') === 'application/json'
                && isset($config['response_json_schema'])
                && ! isset($config['response_schema']);
        });
    });

    test('structured response is correctly parsed', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse(['symbol' => 'Fe']),
        ]);

        $response = (new StructuredAgent)->prompt(
            'What is the symbol for Iron?',
            provider: 'gemini',
        );

        expect($response->structured['symbol'])->toBe('Fe');
    });

    test('nested structured output uses response json schema', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse([
                'elements' => [['atomicNumber' => 1, 'symbol' => 'H']],
            ]),
        ]);

        (new NestedStructuredAgent)->prompt('List noble gases?', provider: 'gemini');

        Http::assertSent(function ($request) {
            $config = $request->data()['generationConfig'] ?? [];
            $schema = $config['response_json_schema'] ?? [];
            $itemSchema = $schema['properties']['elements']['items'] ?? [];

            return isset($config['response_json_schema'])
                && ! isset($config['response_schema'])
                && isset($itemSchema['additionalProperties'])
                && $itemSchema['additionalProperties'] === false;
        });
    });

    test('nullable schema types are preserved in response json schema', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse([
                'symbol' => 'He',
                'meltingPoint' => null,
                'boilingPoint' => -268.9,
            ]),
        ]);

        (new NullableStructuredAgent)->prompt('Properties of Helium?', provider: 'gemini');

        Http::assertSent(function ($request) {
            $schema = $request->data()['generationConfig']['response_json_schema'] ?? [];
            $props = $schema['properties'] ?? [];

            return $props['meltingPoint']['type'] === ['number', 'null']
                && $props['boilingPoint']['type'] === ['number', 'null'];
        });
    });
});

describe('usage parsing', function () {
    test('response usage is correctly parsed', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Hello']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 25,
                    'candidatesTokenCount' => 15,
                    'totalTokenCount' => 40,
                    'cachedContentTokenCount' => 5,
                    'thoughtsTokenCount' => 10,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        expect($response->usage)
            ->promptTokens->toBe(20)
            ->completionTokens->toBe(15)
            ->cacheReadInputTokens->toBe(5)
            ->reasoningTokens->toBe(10);
    });

    test('usage without cached tokens uses full prompt count', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Hi']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 100,
                    'candidatesTokenCount' => 50,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        expect($response->usage)
            ->promptTokens->toBe(100)
            ->completionTokens->toBe(50);
    });

    test('response meta exposes the provider native response id', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Hi']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 5,
                ],
                'responseId' => 'gemini-resp-123',
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        expect($response->meta->responseId)->toBe('gemini-resp-123')
            ->and($response->steps->first()->meta->responseId)->toBe('gemini-resp-123');
    });

    test('thinking response parts are separated from text', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => 'Internal reasoning...', 'thought' => true],
                            ['text' => 'The answer is 42.'],
                        ],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 20,
                    'thoughtsTokenCount' => 15,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Question?', provider: 'gemini');

        expect($response->text)->toBe('The answer is 42.')->not->toContain('Internal reasoning');
    });

    test('finish reason maps correctly', function (string $geminiReason, FinishReason $expected) {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Response']], 'role' => 'model'],
                    'finishReason' => $geminiReason,
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        expect($response->steps->last()->finishReason)->toBe($expected);
    })->with([
        'STOP maps to Stop' => ['STOP', FinishReason::Stop],
        'MAX_TOKENS maps to Length' => ['MAX_TOKENS', FinishReason::Length],
        'SAFETY maps to ContentFilter' => ['SAFETY', FinishReason::ContentFilter],
        'MALFORMED_FUNCTION_CALL maps to ContentFilter' => ['MALFORMED_FUNCTION_CALL', FinishReason::ContentFilter],
        'RECITATION maps to ContentFilter' => ['RECITATION', FinishReason::ContentFilter],
    ]);
});

describe('citations', function () {
    test('grounding metadata citations are filtered through supports', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Spain won Euro 2024.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                    'groundingMetadata' => [
                        'groundingChunks' => [
                            ['web' => ['uri' => 'https://example.com/euro', 'title' => 'Euro 2024']],
                            ['web' => ['uri' => 'https://example.com/unreferenced', 'title' => 'Not Cited']],
                            ['web' => ['uri' => 'https://example.com/spain', 'title' => 'Spain Wins']],
                        ],
                        'groundingSupports' => [
                            ['segment' => ['startIndex' => 0, 'endIndex' => 20, 'text' => 'Spain won Euro 2024.'], 'groundingChunkIndices' => [0, 2]],
                        ],
                        'webSearchQueries' => ['who won euro 2024'],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Who won Euro 2024?', provider: 'gemini');

        expect($response->meta->citations)->toHaveCount(2)
            ->and($response->meta->citations[0]->url)->toBe('https://example.com/euro')
            ->and($response->meta->citations[1]->url)->toBe('https://example.com/spain');
    });

    test('legacy citation metadata is also extracted', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Some content.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                    'citationMetadata' => [
                        'citationSources' => [
                            ['uri' => 'https://example.com/source1', 'title' => 'Source 1'],
                        ],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Query', provider: 'gemini');

        expect($response->meta->citations)->toHaveCount(1)
            ->and($response->meta->citations[0]->url)->toBe('https://example.com/source1');
    });

    test('duplicate citations are deduplicated by url', function () {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => 'Content.']],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                    'groundingMetadata' => [
                        'groundingChunks' => [
                            ['web' => ['uri' => 'https://example.com/same', 'title' => 'Title A']],
                            ['web' => ['uri' => 'https://example.com/same', 'title' => 'Title B']],
                        ],
                        'groundingSupports' => [
                            ['segment' => ['startIndex' => 0, 'endIndex' => 8], 'groundingChunkIndices' => [0, 1]],
                        ],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt('Query', provider: 'gemini');

        expect($response->meta->citations)->toHaveCount(1);
    });
});
