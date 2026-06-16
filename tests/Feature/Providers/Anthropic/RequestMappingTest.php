<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\StructuredWithThinkingAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

describe('request structure', function () {
    test('request includes model and messages', function () {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Laravel is great'),
        ]);

        (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'anthropic',
            model: 'claude-sonnet-4-6',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $body['model'] === 'claude-sonnet-4-6'
                && $body['messages'][0]['role'] === 'user'
                && $body['messages'][0]['content'][0]['text'] === 'What is Laravel?';
        });
    });

    test('system instructions are sent as top level system field', function () {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['system'])
                && is_string($body['system'])
                && str_contains($body['system'], 'helpful');
        });
    });

    test('max tokens defaults to 64000', function () {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            return $request->data()['max_tokens'] === 64000;
        });
    });

    test('temperature and top_p are included when set via attributes', function () {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AttributeAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['temperature'] === 0.7
                && $body['top_p'] === 0.8;
        });
    });

    test('temperature and top_p are excluded when not set', function () {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! array_key_exists('temperature', $body)
                && ! array_key_exists('top_p', $body);
        });
    });

    test('tools with structured output use tool choice any when native structured output is disabled', function () {
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

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['tools'])
                && count($body['tools']) > 0
                && $body['tool_choice']['type'] === 'any';
        });
    });

    test('request without tools excludes tool fields', function () {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['tools'])
                && ! isset($body['tool_choice']);
        });
    });

    test('request sends correct authentication headers', function () {
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

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01');
        });
    });

    test('request omits the api key header when no key is configured', function () {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
            return ! $request->hasHeader('x-api-key')
                && $request->hasHeader('anthropic-version', '2023-06-01');
        });
    });
});

describe('structured output', function () {
    test('structured output uses native output_config by default', function () {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request) {
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

    test('structured output falls back to the synthetic tool when native structured output is disabled', function () {
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

        Http::assertSent(function ($request) {
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

    test('structured output with thinking uses auto tool choice when native structured output is disabled', function () {
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

        Http::assertSent(function ($request) {
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

    test('native structured response is correctly parsed', function () {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
        ]);

        $response = (new StructuredAgent)->prompt(
            'Tell me about Taylor',
            provider: 'anthropic',
        );
        expect($response->structured)->toMatchArray(['name' => 'Taylor', 'age' => 30]);
    });

    test('synthetic tool structured response is correctly parsed when native structured output is disabled', function () {
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

describe('response parsing', function () {
    test('response text is correctly parsed', function () {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Laravel is a PHP framework'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'anthropic',
        );

        expect($response->text)->toBe('Laravel is a PHP framework');
    });

    test('response meta exposes the provider native response id', function () {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('Laravel is a PHP framework'),
        ]);

        $response = (new AssistantAgent)->prompt(
            'What is Laravel?',
            provider: 'anthropic',
        );

        expect($response->meta->responseId)->toBe('msg_123')
            ->and($response->steps->first()->meta->responseId)->toBe('msg_123');
    });

    test('response usage is correctly parsed', function () {
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
