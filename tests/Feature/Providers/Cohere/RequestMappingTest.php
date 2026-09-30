<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\AttributeToolChoiceAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\ToolChoiceAgent;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
    ]]);
});

test('request is sent to the chat endpoint with model and messages', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hi there', provider: 'cohere', model: 'command-r7b-12-2024');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.cohere.com/v2/chat'
            && $body['model'] === 'command-r7b-12-2024'
            && collect($body['messages'])->contains(fn ($m): bool => $m['role'] === 'user' && $m['content'] === 'Hi there');
    });
});

test('default text model is used when none is given', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'cohere');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['model'] === 'command-a-03-2025');
});

test('system instructions are sent as system message', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'cohere');

    Http::assertSent(function (Request $request): bool {
        $systemMsg = collect(json_decode($request->body(), true)['messages'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains((string) $systemMsg['content'], 'helpful assistant');
    });
});

test('generation options are mapped when set via attributes', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'cohere');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return data_get($body, 'temperature') === 0.7
            && data_get($body, 'max_tokens') === 4096
            && data_get($body, 'p') === 0.8
            && ! array_key_exists('top_p', $body);
    });
});

test('generation options are excluded when not set', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'cohere');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_tokens', $body)
            && ! array_key_exists('p', $body);
    });
});

test('tools are sent without a tool choice by default', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('42')]);

    agent(tools: [new RandomNumberGenerator])->prompt('Give me a number', provider: 'cohere');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tool_choice', $body)
            && $body['tools'][0]['type'] === 'function'
            && $body['tools'][0]['function']['name'] === 'RandomNumberGenerator';
    });
});

test('request without tools excludes tool fields', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    agent()->prompt('Hello', provider: 'cohere');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('tools', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

test('required tool choice is sent in uppercase', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('42')]);

    (new ToolChoiceAgent('required'))->prompt('Give me a number', provider: 'cohere');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'REQUIRED');
});

test('required tool choice can be set via attribute', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('42')]);

    (new AttributeToolChoiceAgent)->prompt('Give me a number', provider: 'cohere');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'REQUIRED');
});

test('none tool choice is sent in uppercase', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Sure')]);

    (new ToolChoiceAgent('none'))->prompt('Just talk', provider: 'cohere');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['tool_choice'] === 'NONE');
});

test('named tool choice only offers that tool and requires a call', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('42')]);

    (new ToolChoiceAgent(['tool' => 'custom_named_tool']))->prompt('Give me a number', provider: 'cohere');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tool_choice'] === 'REQUIRED'
            && count($body['tools']) === 1
            && $body['tools'][0]['function']['name'] === 'custom_named_tool';
    });
});

test('named tool choice for an unknown tool throws', function (): void {
    Http::fake();

    (new ToolChoiceAgent(['tool' => 'missing_tool']))->prompt('Give me a number', provider: 'cohere');
})->throws(InvalidArgumentException::class, 'Tool choice [missing_tool] does not match any of the available tools.');

test('structured output sends a json object response format', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('{"symbol":"Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'cohere');

    Http::assertSent(function (Request $request): bool {
        $format = data_get(json_decode($request->body(), true), 'response_format');

        return $format['type'] === 'json_object'
            && $format['json_schema']['type'] === 'object'
            && $format['json_schema']['required'] === ['symbol']
            && ! array_key_exists('name', $format['json_schema']);
    });
});

test('schema combined with tools omits response format but keeps schema instructions', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('{"number": 42}')]);

    agent(
        tools: [new RandomNumberGenerator],
        schema: fn ($s): array => ['number' => $s->integer()->required()],
    )->prompt('Give me a number', provider: 'cohere');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

        return ! array_key_exists('response_format', $body)
            && is_array($body['tools'])
            && $systemMsg !== null
            && str_contains((string) $systemMsg['content'], 'JSON object that strictly adheres');
    });
});

test('streaming request enables streaming without stream options', function (): void {
    Http::fake(['*' => $this->fakeStreamResponse($this->streamTextEvents('Hi'))]);

    $this->collectStreamEvents();

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true
            && ! array_key_exists('stream_options', $body);
    });
});

test('response text is correctly parsed', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Your name is Sam.')]);

    $response = agent()->prompt('What is my name?', provider: 'cohere', model: 'command-a-03-2025');

    expect($response->text)->toBe('Your name is Sam.')
        ->and($response->meta->provider)->toBe('cohere')
        ->and($response->meta->model)->toBe('command-a-03-2025');
});

test('response text joins multiple text blocks', function (): void {
    Http::fake(['*' => $this->fakeTextResponse([
        ['type' => 'text', 'text' => 'Hello'],
        ['type' => 'text', 'text' => ' world'],
    ])]);

    expect(agent()->prompt('Hi', provider: 'cohere')->text)->toBe('Hello world');
});

test('response usage reports token counts and cached tokens', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello')]);

    $response = agent()->prompt('Hello', provider: 'cohere');

    expect($response->usage->inputTokens)->toBe(557)
        ->and($response->usage->outputTokens)->toBe(8)
        ->and($response->usage->cacheReadInputTokens)->toBe(480);
});

test('response usage leaves cached tokens null when absent', function (): void {
    Http::fake(['*' => Http::response([
        'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello']]],
        'finish_reason' => 'COMPLETE',
        'usage' => ['tokens' => ['input_tokens' => 557, 'output_tokens' => 8]],
    ])]);

    expect(agent()->prompt('Hello', provider: 'cohere')->usage->cacheReadInputTokens)->toBeNull();
});

test('finish reasons are mapped', function (string $cohereReason, FinishReason $expected): void {
    Http::fake(['*' => $this->fakeTextResponse('Hello', $cohereReason)]);

    $response = agent()->prompt('Hello', provider: 'cohere');

    expect($response->steps->last()->finishReason)->toBe($expected);
})->with([
    ['COMPLETE', FinishReason::Stop],
    ['STOP_SEQUENCE', FinishReason::Stop],
    ['MAX_TOKENS', FinishReason::Length],
    ['ERROR', FinishReason::Error],
    ['TIMEOUT', FinishReason::Error],
    ['SOMETHING_NEW', FinishReason::Unknown],
]);

test('structured response is correctly parsed', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('{"symbol":"Au"}')]);

    $response = (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'cohere');

    expect($response->structured['symbol'])->toBe('Au');
});
