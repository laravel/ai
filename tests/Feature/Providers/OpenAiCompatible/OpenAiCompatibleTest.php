<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Agents\AttributeAgent;
use Tests\Fixtures\Agents\StructuredAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    configureOpenAiCompatible();
});

test('throws when no url is configured', function () {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'key' => 'test-key',
        'models' => ['text' => ['default' => 'local-model']],
    ]]);

    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello')]);

    expect(fn () => agent()->prompt('Hello', provider: 'openai-compatible'))
        ->toThrow(InvalidArgumentException::class, "requires a 'url'");
});

test('throws when no default model is configured and none is passed', function () {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
    ]]);

    expect(fn () => agent()->prompt('Hello', provider: 'openai-compatible'))
        ->toThrow(InvalidArgumentException::class, 'requires a default text model');
});

test('text requests use the configured base url and path', function () {
    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello from local model')]);

    $response = agent()->prompt('Hello', provider: 'openai-compatible');

    expect($response->text)->toBe('Hello from local model')
        ->and($response->meta->provider)->toBe('openai-compatible');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'http://localhost:1234/v1/chat/completions');
});

test('request sends bearer token authorization', function () {
    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai-compatible');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-key'));
});

test('request omits authorization header when no key is configured', function () {
    config(['ai.providers.openai-compatible' => [
        ...config('ai.providers.openai-compatible'),
        'key' => null,
    ]]);

    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai-compatible');

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
});

test('request works when the key is absent from the config entirely', function () {
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'models' => ['text' => ['default' => 'local-model']],
    ]]);

    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello')]);

    agent()->prompt('Hello', provider: 'openai-compatible');

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
});

test('structured output defaults to json schema response format', function () {
    Http::fake(['*' => fakeOpenAiCompatibleResponse('{"symbol": "Au"}')]);

    (new StructuredAgent)->prompt('What is the symbol for Gold?', provider: 'openai-compatible');

    Http::assertSent(function (Request $request) {
        $format = data_get(json_decode($request->body(), true), 'response_format');

        return $format['type'] === 'json_schema'
            && $format['json_schema']['strict'] === true;
    });
});

test('max tokens uses the max_tokens field by default', function () {
    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello')]);

    (new AttributeAgent)->prompt('Hello', provider: 'openai-compatible');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return data_get($body, 'max_tokens') === 4096
            && ! array_key_exists('max_completion_tokens', $body);
    });
});

test('response usage is parsed using the openai standard shape', function () {
    Http::fake(['*' => Http::response([
        'id' => 'chatcmpl-1',
        'object' => 'chat.completion',
        'model' => 'local-model',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'Hello'],
            'finish_reason' => 'stop',
        ]],
        'usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'prompt_tokens_details' => ['cached_tokens' => 40],
            'completion_tokens_details' => ['reasoning_tokens' => 10],
        ],
    ])]);

    $response = agent()->prompt('Hello', provider: 'openai-compatible');

    expect($response->usage->promptTokens)->toBe(100)
        ->and($response->usage->completionTokens)->toBe(50)
        ->and($response->usage->cacheReadInputTokens)->toBe(40)
        ->and($response->usage->reasoningTokens)->toBe(10);
});

test('streaming omits stream_options by default', function () {
    Http::fake(['*' => fakeOpenAiCompatibleStream()]);

    foreach (agent()->stream('Hello', provider: 'openai-compatible') as $event) {
        // drain the stream
    }

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['stream'] === true
            && ! array_key_exists('stream_options', $body);
    });
});

test('streaming sends stream_options when configured on the instance', function () {
    config(['ai.providers.openai-compatible' => [
        ...config('ai.providers.openai-compatible'),
        'stream_options' => ['include_usage' => true],
    ]]);

    Http::fake(['*' => fakeOpenAiCompatibleStream()]);

    foreach (agent()->stream('Hello', provider: 'openai-compatible') as $event) {
        // drain the stream
    }

    Http::assertSent(function (Request $request) {
        return data_get(json_decode($request->body(), true), 'stream_options.include_usage') === true;
    });
});

test('streaming sends stream_options supplied via provider options', function () {
    Http::fake(['*' => fakeOpenAiCompatibleStream()]);

    $agent = new class implements Agent, HasProviderOptions
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['stream_options' => ['include_usage' => true]];
        }
    };

    foreach ($agent->stream('Hello', provider: 'openai-compatible') as $event) {
        // drain the stream
    }

    Http::assertSent(function (Request $request) {
        return data_get(json_decode($request->body(), true), 'stream_options.include_usage') === true;
    });
});

test('custom named instances use their own configured base url and model', function () {
    config(['ai.providers.lm-studio' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:4321/v1',
        'key' => 'lm-studio-key',
        'models' => ['text' => ['default' => 'lm-studio-model']],
    ]]);

    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello from LM Studio')]);

    $response = agent()->prompt('Hello', provider: 'lm-studio');

    expect($response->text)->toBe('Hello from LM Studio')
        ->and($response->meta->provider)->toBe('lm-studio');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:4321/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer lm-studio-key')
        && data_get(json_decode($request->body(), true), 'model') === 'lm-studio-model');
});

test('named instances resolve provider options by their instance name', function () {
    config(['ai.providers.lm-studio' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:4321/v1',
        'key' => 'lm-studio-key',
        'models' => ['text' => ['default' => 'lm-studio-model']],
    ]]);

    config(['ai.providers.vllm' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:8000/v1',
        'key' => 'vllm-key',
        'models' => ['text' => ['default' => 'vllm-model']],
    ]]);

    Http::fake(['*' => fakeOpenAiCompatibleResponse('Hello')]);

    $agent = new class implements Agent, HasProviderOptions
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return match ($provider) {
                'lm-studio' => ['top_k' => 40],
                'vllm' => ['top_k' => 10],
                default => [],
            };
        }
    };

    $agent->prompt('Hello', provider: 'lm-studio');
    $agent->prompt('Hello', provider: 'vllm');

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:4321/v1/chat/completions'
        && data_get(json_decode($request->body(), true), 'top_k') === 40);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:8000/v1/chat/completions'
        && data_get(json_decode($request->body(), true), 'top_k') === 10);
});

function configureOpenAiCompatible(): void
{
    config(['ai.providers.openai-compatible' => [
        'driver' => 'openai-compatible',
        'url' => 'http://localhost:1234/v1',
        'key' => 'test-key',
        'models' => ['text' => ['default' => 'local-model']],
    ]]);
}

function fakeOpenAiCompatibleResponse(string $content)
{
    return Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'local-model',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ]);
}

function fakeOpenAiCompatibleStream()
{
    $chunk = fn (array $delta, ?string $finish = null) => 'data: '.json_encode([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion.chunk',
        'model' => 'local-model',
        'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]],
    ]);

    $body = implode("\n\n", [
        $chunk(['role' => 'assistant', 'content' => 'Hello']),
        $chunk([], 'stop'),
        'data: [DONE]',
    ])."\n\n";

    return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
}
