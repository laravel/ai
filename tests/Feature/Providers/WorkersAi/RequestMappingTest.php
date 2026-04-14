<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('workersai sends model in request body', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(function (Request $r) {
        $body = json_decode($r->body(), true);

        return $body['model'] === '@cf/meta/llama-3.3-70b-instruct-fp8-fast';
    });
});

test('workersai sends max_completion_tokens when set via attributes', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    (new \Tests\Fixtures\Agents\AttributeAgent)->prompt('Hello', provider: 'workersai');

    Http::assertSent(function (Request $r) {
        $body = json_decode($r->body(), true);

        return data_get($body, 'max_completion_tokens') === 4096
            && ! array_key_exists('max_tokens', $body);
    });
});

test('workersai excludes max_completion_tokens when not set', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(function (Request $r) {
        $body = json_decode($r->body(), true);

        return ! array_key_exists('max_completion_tokens', $body)
            && ! array_key_exists('max_tokens', $body);
    });
});

test('workersai coerces user message content to string', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    agent()->prompt('Hello', provider: 'workersai');

    Http::assertSent(function (Request $r) {
        $body = json_decode($r->body(), true);
        $userMsg = collect($body['messages'])->firstWhere('role', 'user');

        return is_string($userMsg['content']);
    });
});

test('workersai sends stream_options in streaming requests', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            body: $this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Hi']),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 5, 'completion_tokens' => 1]),
                '[DONE]',
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $this->collectStreamEvents();

    Http::assertSent(function (Request $r) {
        $body = json_decode($r->body(), true);

        return ($body['stream'] ?? false) === true
            && ($body['stream_options']['include_usage'] ?? false) === true;
    });
});

test('workersai sends structured output with json_schema', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => '{"answer":"42"}',
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    agent()->prompt('What is the answer?', provider: 'workersai');

    Http::assertSentCount(1);
});
