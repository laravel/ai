<?php

namespace Tests\Feature\Providers;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\Gemini\GeminiGateway;
use Laravel\Ai\Gateway\Groq\GroqGateway;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\AnthropicProvider;
use Laravel\Ai\Providers\GeminiProvider;
use Laravel\Ai\Providers\GroqProvider;
use Laravel\Ai\Providers\OpenAiProvider;
use Laravel\Ai\Providers\Provider;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\TestCase;

#[Timeout(300)]
class TimeoutToolAgent implements Agent, HasTools
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

class SpyOpenAiGateway extends OpenAiGateway
{
    public array $capturedTimeouts = [];

    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $this->capturedTimeouts[] = $timeout;

        return parent::client($provider, $timeout);
    }
}

class SpyAnthropicGateway extends AnthropicGateway
{
    public array $capturedTimeouts = [];

    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $this->capturedTimeouts[] = $timeout;

        return parent::client($provider, $timeout);
    }
}

class SpyGroqGateway extends GroqGateway
{
    public array $capturedTimeouts = [];

    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $this->capturedTimeouts[] = $timeout;

        return parent::client($provider, $timeout);
    }
}

class SpyGeminiGateway extends GeminiGateway
{
    public array $capturedTimeouts = [];

    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $this->capturedTimeouts[] = $timeout;

        return parent::client($provider, $timeout);
    }
}

class TimeoutInToolLoopTest extends TestCase
{
    public function test_openai_timeout_is_preserved_in_tool_call_follow_up(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeOpenAiToolCallResponse(),
                $this->fakeOpenAiTextResponse('The number is 72019'),
            ]),
        ]);

        config(['ai.providers.openai.key' => 'test-key']);

        $spy = new SpyOpenAiGateway(app(Dispatcher::class));
        $manager = app(AiManager::class);
        $manager->purge('openai');
        $manager->extend('openai', fn ($app, array $config) => new OpenAiProvider(
            $spy, $config, app(Dispatcher::class),
        ));

        (new TimeoutToolAgent)->prompt('Give me a number', provider: 'openai');

        $this->assertCount(2, $spy->capturedTimeouts);
        $this->assertSame(300, $spy->capturedTimeouts[0]);
        $this->assertSame(300, $spy->capturedTimeouts[1]);
    }

    public function test_anthropic_timeout_is_preserved_in_tool_call_follow_up(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeAnthropicToolCallResponse(),
                $this->fakeAnthropicTextResponse('The number is 72019'),
            ]),
        ]);

        config(['ai.providers.anthropic.key' => 'test-key']);

        $spy = new SpyAnthropicGateway(app(Dispatcher::class));
        $manager = app(AiManager::class);
        $manager->purge('anthropic');
        $manager->extend('anthropic', fn ($app, array $config) => new AnthropicProvider(
            $spy, $config, app(Dispatcher::class),
        ));

        (new TimeoutToolAgent)->prompt('Give me a number', provider: 'anthropic');

        $this->assertCount(2, $spy->capturedTimeouts);
        $this->assertSame(300, $spy->capturedTimeouts[0]);
        $this->assertSame(300, $spy->capturedTimeouts[1]);
    }

    public function test_groq_timeout_is_preserved_in_tool_call_follow_up(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeGroqToolCallResponse(),
                $this->fakeGroqTextResponse('The number is 72019'),
            ]),
        ]);

        config(['ai.providers.groq.key' => 'test-key']);

        $spy = new SpyGroqGateway(app(Dispatcher::class));

        $manager = app(AiManager::class);
        $manager->purge('groq');
        $manager->extend('groq', function ($app, array $config) use ($spy) {
            $provider = new GroqProvider($config, app(Dispatcher::class));
            $provider->useTextGateway($spy);

            return $provider;
        });

        (new TimeoutToolAgent)->prompt('Give me a number', provider: 'groq');

        $this->assertCount(2, $spy->capturedTimeouts);
        $this->assertSame(300, $spy->capturedTimeouts[0]);
        $this->assertSame(300, $spy->capturedTimeouts[1]);
    }

    public function test_gemini_timeout_is_preserved_in_tool_call_follow_up(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeGeminiToolCallResponse(),
                $this->fakeGeminiTextResponse('The number is 72019'),
            ]),
        ]);

        config(['ai.providers.gemini.key' => 'test-key']);

        $spy = new SpyGeminiGateway(app(Dispatcher::class));
        $manager = app(AiManager::class);
        $manager->purge('gemini');
        $manager->extend('gemini', fn ($app, array $config) => new GeminiProvider(
            $spy, $config, app(Dispatcher::class),
        ));

        (new TimeoutToolAgent)->prompt('Give me a number', provider: 'gemini');

        $this->assertCount(2, $spy->capturedTimeouts);
        $this->assertSame(300, $spy->capturedTimeouts[0]);
        $this->assertSame(300, $spy->capturedTimeouts[1]);
    }

    protected function fakeOpenAiToolCallResponse(): PromiseInterface
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

    protected function fakeOpenAiTextResponse(string $text): PromiseInterface
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

    protected function fakeAnthropicToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'msg_tool_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_123',
                'name' => 'FixedNumberGenerator',
                'input' => (object) [],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function fakeAnthropicTextResponse(string $text): PromiseInterface
    {
        return Http::response([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }

    protected function fakeGroqToolCallResponse(): PromiseInterface
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

    protected function fakeGroqTextResponse(string $text): PromiseInterface
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

    protected function fakeGeminiToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'id' => 'call_123',
                            'name' => 'FixedNumberGenerator',
                            'args' => (object) [],
                        ],
                    ]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
            'modelVersion' => 'gemini-3-flash-preview',
        ]);
    }

    protected function fakeGeminiTextResponse(string $text): PromiseInterface
    {
        return Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => $text]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
            'modelVersion' => 'gemini-3-flash-preview',
        ]);
    }
}
