<?php

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\Feature\Agents\StructuredWithThinkingAgent;
use Tests\Feature\Agents\ToolUsingAgent;

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

test('tools with structured output use tool choice any', function () {
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

test('structured output uses synthetic tool', function () {
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

        return $hasStructuredTool
            && $body['tool_choice']['type'] === 'tool'
            && $body['tool_choice']['name'] === 'output_structured_data';
    });
});

test('request sends correct authentication headers', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-api-key')
            && $request->hasHeader('anthropic-version', '2023-06-01');
    });
});

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

    expect($response->usage->promptTokens)->toBe(25);
    expect($response->usage->completionTokens)->toBe(15);
});

test('structured output with thinking uses auto tool choice', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
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

test('structured response is correctly parsed', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
    ]);

    $response = (new StructuredAgent)->prompt(
        'Tell me about Taylor',
        provider: 'anthropic',
    );

    expect($response->structured['name'])->toBe('Taylor');
    expect($response->structured['age'])->toBe(30);
});
