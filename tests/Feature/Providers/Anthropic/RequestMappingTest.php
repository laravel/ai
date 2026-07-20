<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\AgentResponse;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\AttributeToolChoiceAgent;
use Tests\Fixtures\Agents\ConstrainedStructuredAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\StructuredWithThinkingAgent;
use Tests\Fixtures\Agents\ThinkingToolChoiceAgent;
use Tests\Fixtures\Agents\ToolChoiceAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

describe('request structure', function (): void {
    test('request includes model and messages', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Laravel is great'),
        ]);

        (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $body['model'] === 'claude-sonnet-4-6'
                && $body['messages'][0]['role'] === 'user'
                && $body['messages'][0]['content'][0]['text'] === 'What is Laravel?';
        });
    });

    test('system instructions are sent as top level system field', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return isset($body['system'])
                && is_string($body['system'])
                && str_contains($body['system'], 'helpful');
        });
    });

    test('max tokens defaults to 64000', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(fn ($request): bool => $request->data()['max_tokens'] === 64000);
    });

    test('temperature and top_p are included when set via attributes', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AttributeAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['temperature'] === 0.7
                && $body['top_p'] === 0.8;
        });
    });

    test('temperature and top_p are excluded when not set', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('top_p', $body);
        });
    });

    test('tools with structured output use tool choice any when native structured output is disabled', function (): void {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'use_native_structured_output' => false,
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return isset($body['tools'])
                && count($body['tools']) > 0
                && $body['tool_choice']['type'] === 'any';
        });
    });

    test('request without tools excludes tool fields', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return ! isset($body['tools'])
                && ! isset($body['tool_choice']);
        });
    });

    test('request sends correct authentication headers', function (): void {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'key' => 'test-key',
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(fn ($request): bool => $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01'));
    });

    test('request omits the api key header when no key is configured', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(fn ($request): bool => ! $request->hasHeader('x-api-key')
            && $request->hasHeader('anthropic-version', '2023-06-01'));
    });
});

describe('structured output', function (): void {
    test('structured output uses native output_config by default', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $hasStructuredTool = false;

            foreach ($body['tools'] ?? [] as $tool) {
                if ($tool['name'] === 'output_structured_data') {
                    $hasStructuredTool = true;
                }
            }

            return $body['output_config']['format']['type'] === 'json_schema'
                && ! $hasStructuredTool;
        });
    });

    test('native structured output strips unsupported constraints and folds them into descriptions', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['score' => 5, 'tags' => ['a']]),
        ]);

        (new ConstrainedStructuredAgent)->prompt(
            'Score this',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $schema = $request->data()['output_config']['format']['schema'];

            $score = $schema['properties']['score'];
            $tags = $schema['properties']['tags'];

            return ! isset($score['minimum'])
                && ! isset($score['maximum'])
                && str_contains($score['description'], 'Must be at least 1.')
                && str_contains($score['description'], 'Must be at most 10.')
                && ! isset($tags['maxItems'])
                && ! isset($tags['items']['maxLength'])
                && str_contains($tags['description'], 'Must contain at most 5 items.')
                && str_contains($tags['items']['description'], 'Must be at most 20 characters.');
        });
    });

    test('structured output falls back to the synthetic tool when native structured output is disabled', function (): void {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'use_native_structured_output' => false,
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $hasStructuredTool = false;

            foreach ($body['tools'] ?? [] as $tool) {
                if ($tool['name'] === 'output_structured_data') {
                    $hasStructuredTool = true;
                }
            }

            return $hasStructuredTool
                && $body['tool_choice']['type'] === 'tool'
                && $body['tool_choice']['name'] === 'output_structured_data';
        });
    });

    test('structured output with thinking uses auto tool choice when native structured output is disabled', function (): void {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'use_native_structured_output' => false,
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new StructuredWithThinkingAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $hasStructuredTool = false;

            foreach ($body['tools'] ?? [] as $tool) {
                if ($tool['name'] === 'output_structured_data') {
                    $hasStructuredTool = true;
                }
            }

            return $hasStructuredTool
                && $body['tool_choice']['type'] === 'auto'
                && $body['thinking']['type'] === 'enabled';
        });
    });

    test('native structured response is correctly parsed', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        $response = (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );
        expect($response->structured)->toMatchArray(['name' => 'Taylor', 'age' => 30]);
    });

    test('synthetic tool structured response is correctly parsed when native structured output is disabled', function (): void {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'use_native_structured_output' => false,
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeSyntheticStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        $response = (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );
        expect($response->structured)->toMatchArray(['name' => 'Taylor', 'age' => 30]);
    });
});

describe('response parsing', function (): void {
    test('response text is correctly parsed', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Laravel is a PHP framework'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'anthropic',
        );

        expect($response->text)->toBe('Laravel is a PHP framework');
    });

    test('response usage is correctly parsed', function (): void {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'Hello']],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 25,
                    'output_tokens' => 15,
                    'cache_creation_input_tokens' => 5,
                    'cache_read_input_tokens' => 3,
                ],
            ]),
        ]);

        $response = (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        expect($response->usage)
            ->promptTokens->toBe(25)
            ->completionTokens->toBe(15);
    });
});

describe('tool choice', function (): void {
    test('required tool choice maps to any', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolChoiceAgent('required'))->prompt('Generate a number', provider: 'anthropic');

        Http::assertSent(fn ($request): bool => $request->data()['tool_choice'] === ['type' => 'any']);
    });

    test('required tool choice can be set via attribute', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new AttributeToolChoiceAgent)->prompt('Generate a number', provider: 'anthropic');

        Http::assertSent(fn ($request): bool => $request->data()['tool_choice'] === ['type' => 'any']);
    });

    test('named tool choice maps to a specific tool', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Generate a number', provider: 'anthropic');

        Http::assertSent(fn ($request): bool => $request->data()['tool_choice'] === ['type' => 'tool', 'name' => 'custom_named_tool']);
    });

    test('none tool choice prevents tool calls', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Sure'),
        ]);

        (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'anthropic');

        Http::assertSent(fn ($request): bool => $request->data()['tool_choice'] === ['type' => 'none']);
    });

    test('forcing a tool while thinking is enabled throws', function (): void {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        expect(fn (): AgentResponse => (new ThinkingToolChoiceAgent)->prompt('Generate a number', provider: 'anthropic'))
            ->toThrow(
                InvalidArgumentException::class,
                'Anthropic cannot force tool use while extended thinking is enabled.',
            );
    });
});
