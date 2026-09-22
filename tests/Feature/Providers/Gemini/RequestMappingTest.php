<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeToolChoiceAgent;
use Tests\Fixtures\Agents\NestedStructuredAgent;
use Tests\Fixtures\Agents\NullableStructuredAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\ToolChoiceAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

describe('request structure', function (): void {
    test('request posts to the interactions endpoint with the model in the body', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('Laravel is great'),
        ]);

        (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'gemini',
            model: 'gemini-3.7-flash',
        );

        expect(sentRequest()->url())->toEndWith('/interactions')
            ->and(sentRequest()->data())->toMatchArray(['model' => 'gemini-3.7-flash'])
            ->and(sentRequest()->data()['input'][0])->toMatchArray([
                'type' => 'user_input',
                'content' => [['type' => 'text', 'text' => 'What is Laravel?']],
            ]);
    });

    test('history is replayed rather than left to gemini to store', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        expect(sentRequest()->data())->toMatchArray(['store' => false])
            ->not->toHaveKey('previous_interaction_id');
    });

    test('system instructions are sent as a plain string', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        expect(sentRequest()->data()['system_instruction'])->toBeString()->toContain('helpful');
    });

    test('request without tools excludes tool fields', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        expect(sentRequest()->data())->not->toHaveKey('tools')
            ->and(sentRequest()->data()['generation_config'] ?? [])->not->toHaveKey('tool_choice');
    });

    test('request sends api key header', function (): void {
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

        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key'));
    });

    test('request omits the api key header when no key is configured', function (): void {
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

        Http::assertSent(fn ($request): bool => ! $request->hasHeader('x-goog-api-key'));
    });

    test('tool choice is omitted to rely on the Gemini default', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'gemini',
        );

        expect(sentRequest()->data())->toHaveKey('tools')
            ->and(sentRequest()->data()['generation_config'] ?? [])->not->toHaveKey('tool_choice');
    });

    test('function call id is extracted from response', function (): void {
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

describe('structured output', function (): void {
    test('structured output uses the response format schema', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse(['symbol' => 'Fe']),
        ]);

        (new StructuredAgent)->prompt(
            'What is the symbol for Iron?',
            provider: 'gemini',
        );

        expect(sentRequest()->data()['response_format'])
            ->toMatchArray(['type' => 'text', 'mime_type' => 'application/json'])
            ->and(sentRequest()->data()['response_format']['schema']['properties'])->toHaveKey('symbol');
    });

    test('structured response is correctly parsed', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse(['symbol' => 'Fe']),
        ]);

        $response = (new StructuredAgent)->prompt(
            'What is the symbol for Iron?',
            provider: 'gemini',
        );

        expect($response->structured['symbol'])->toBe('Fe');
    });

    test('nested structured output keeps the item schema closed', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse([
                'elements' => [['atomicNumber' => 1, 'symbol' => 'H']],
            ]),
        ]);

        (new NestedStructuredAgent)->prompt('List noble gases?', provider: 'gemini');

        expect(sentRequest()->data()['response_format']['schema']['properties']['elements']['items'])
            ->toMatchArray(['additionalProperties' => false]);
    });

    test('nullable schema types are preserved in the response schema', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeStructuredResponse([
                'symbol' => 'He',
                'meltingPoint' => null,
                'boilingPoint' => -268.9,
            ]),
        ]);

        (new NullableStructuredAgent)->prompt('Properties of Helium?', provider: 'gemini');

        expect(sentRequest()->data()['response_format']['schema']['properties'])->toMatchArray([
            'meltingPoint' => ['type' => ['number', 'null']],
            'boilingPoint' => ['type' => ['number', 'null']],
        ]);
    });
});

describe('usage parsing', function (): void {
    test('response usage is correctly parsed', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction(
                [$this->modelOutput('Hello')],
                [
                    'total_input_tokens' => 25,
                    'total_output_tokens' => 15,
                    'total_tokens' => 40,
                    'total_cached_tokens' => 5,
                    'total_thought_tokens' => 10,
                ],
            )),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );

        expect($response->usage)
            ->inputTokens->toBe(25)
            ->outputTokens->toBe(25)
            ->cacheReadInputTokens->toBe(5)
            ->reasoningTokens->toBe(10);
    });

    test('usage without cached tokens uses full prompt count', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction(
                [$this->modelOutput('Hi')],
                ['total_input_tokens' => 100, 'total_output_tokens' => 50, 'total_tokens' => 150],
            )),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        expect($response->usage)
            ->inputTokens->toBe(100)
            ->outputTokens->toBe(50)
            ->cacheReadInputTokens->toBeNull();
    });

    test('thought steps are separated from the answer text', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction([
                $this->thoughtStep('Internal reasoning...'),
                $this->modelOutput('The answer is 42.'),
            ])),
        ]);

        $response = (new AssistantAgent)->prompt('Question?', provider: 'gemini');

        expect($response->text)->toBe('The answer is 42.')->not->toContain('Internal reasoning');
    });

    test('finish reason maps from the interaction status', function (string $status, FinishReason $expected): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->fakeInteraction([$this->modelOutput('Response')], [], $status),
            ),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        expect($response->steps->last()->finishReason)->toBe($expected);
    })->with([
        'completed maps to Stop' => ['completed', FinishReason::Stop],
        'incomplete maps to Length' => ['incomplete', FinishReason::Length],
        'requires_action maps to ToolCalls' => ['requires_action', FinishReason::ToolCalls],
    ]);

    test('a safety error maps to the content filter finish reason', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(array_merge(
                $this->fakeInteraction([$this->modelOutput('')], [], 'failed'),
                ['errors' => [['code' => 'BLOCKED_SAFETY', 'message' => 'Blocked for safety reasons.']]],
            )),
        ]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        expect($response->steps->last()->finishReason)->toBe(FinishReason::ContentFilter);
    });
});

describe('citations', function (): void {
    test('annotations on the model output become citations', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction([
                ['type' => 'google_search_call', 'content' => [['type' => 'text', 'text' => 'who won euro 2024']]],
                $this->modelOutput('Spain won Euro 2024.', [
                    ['start_index' => 0, 'end_index' => 20, 'uri' => 'https://example.com/euro', 'title' => 'Euro 2024'],
                    ['start_index' => 0, 'end_index' => 20, 'uri' => 'https://example.com/spain', 'title' => 'Spain Wins'],
                ]),
            ])),
        ]);

        $response = (new AssistantAgent)->prompt('Who won Euro 2024?', provider: 'gemini');

        expect($response->meta->citations)->toHaveCount(2)
            ->and($response->meta->citations[0]->url)->toBe('https://example.com/euro')
            ->and($response->meta->citations[0]->title)->toBe('Euro 2024')
            ->and($response->meta->citations[1]->url)->toBe('https://example.com/spain');
    });

    test('duplicate citations are deduplicated by url', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->fakeInteraction([
                $this->modelOutput('Content.', [
                    ['uri' => 'https://example.com/same', 'title' => 'Title A'],
                    ['uri' => 'https://example.com/same', 'title' => 'Title B'],
                ]),
            ])),
        ]);

        $response = (new AssistantAgent)->prompt('Query', provider: 'gemini');

        expect($response->meta->citations)->toHaveCount(1);
    });

    test('a response without annotations reports no citations', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('No sources here.'),
        ]);

        $response = (new AssistantAgent)->prompt('Query', provider: 'gemini');

        expect($response->meta->citations)->toBeEmpty();
    });
});

describe('tool choice', function (): void {
    test('required tool choice sends any mode', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolChoiceAgent('required'))->prompt('Generate a number', provider: 'gemini');

        expect(sentRequest()->data()['generation_config'])->toMatchArray(['tool_choice' => 'any']);
    });

    test('required tool choice can be set via attribute', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new AttributeToolChoiceAgent)->prompt('Generate a number', provider: 'gemini');

        expect(sentRequest()->data()['generation_config'])->toMatchArray(['tool_choice' => 'any']);
    });

    test('named tool choice restricts the allowed tools', function (): void {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Generate a number', provider: 'gemini');

        expect(sentRequest()->data()['generation_config']['tool_choice'])->toBe([
            'allowed_tools' => [
                'mode' => 'any',
                'tools' => ['custom_named_tool'],
            ],
        ]);
    });
});
