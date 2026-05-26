<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Gateway\Groq\GroqGateway;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\GroqProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Tests\Fixtures\Tools\FixedNumberGenerator;

#[Temperature(0.2)]
#[MaxTokens(1024)]
class TextGenOptionsToolAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [new FixedNumberGenerator];
    }
}

function textGenFakeOpenAiToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'resp_tool_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_123',
            'call_id' => 'call_123',
            'name' => 'FixedNumberGenerator',
            'arguments' => '{}',
            'status' => 'completed',
        ]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function textGenFakeOpenAiTextResponse(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'resp_123',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'message',
            'status' => 'completed',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ]);
}

function textGenFakeGroqToolCallResponse(): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-tool-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_123',
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ]);
}

function textGenFakeGroqTextResponse(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'chatcmpl-123',
        'object' => 'chat.completion',
        'model' => 'openai/gpt-oss-20b',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $text],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
    ]);
}

test('openai temperature and max tokens are preserved in tool call follow up', function () {
    Http::fake([
        '*' => Http::sequence([
            textGenFakeOpenAiToolCallResponse(),
            textGenFakeOpenAiTextResponse('The number is 72019'),
        ]),
    ]);

    config(['ai.providers.openai.key' => 'test-key']);

    $gateway = new OpenAiGateway(app(Dispatcher::class));
    $manager = app(AiManager::class);
    $manager->purge('openai');
    $manager->extend('openai', fn ($app, array $config) => new OpenAiProvider(
        $gateway, $config, app(Dispatcher::class),
    ));

    (new TextGenOptionsToolAgent)->prompt('Give me a number', provider: 'openai');

    Http::assertSentInOrder([
        fn (Request $request) => $request['temperature'] === 0.2
            && $request['max_output_tokens'] === 1024,
        fn (Request $request) => $request['temperature'] === 0.2
            && $request['max_output_tokens'] === 1024
            && ! isset($request['previous_response_id']),
    ]);
});

test('groq temperature and max tokens are preserved in tool call follow up', function () {
    Http::fake([
        '*' => Http::sequence([
            textGenFakeGroqToolCallResponse(),
            textGenFakeGroqTextResponse('The number is 72019'),
        ]),
    ]);

    config(['ai.providers.groq.key' => 'test-key']);

    $gateway = new GroqGateway(app(Dispatcher::class));
    $manager = app(AiManager::class);
    $manager->purge('groq');
    $manager->extend('groq', function ($app, array $config) use ($gateway) {
        $provider = new GroqProvider($config, app(Dispatcher::class));
        $provider->useTextGateway($gateway);

        return $provider;
    });

    (new TextGenOptionsToolAgent)->prompt('Give me a number', provider: 'groq');

    Http::assertSentInOrder([
        fn (Request $request) => $request['temperature'] === 0.2
            && $request['max_completion_tokens'] === 1024,
        fn (Request $request) => $request['temperature'] === 0.2
            && $request['max_completion_tokens'] === 1024,
    ]);
});
