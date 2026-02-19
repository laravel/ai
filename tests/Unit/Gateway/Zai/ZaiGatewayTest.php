<?php

namespace Tests\Unit\Gateway\Zai;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Gateway\Zai\Exceptions\ModelNotSupportedException;
use Laravel\Ai\Gateway\Zai\Streaming\ServerSentEventsStreamParser;
use Laravel\Ai\Gateway\Zai\ZaiGateway;
use Laravel\Ai\Providers\ZaiProvider;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use ReflectionClass;
use Tests\TestCase;
use Tests\Unit\Tools\TestTool;

class ZaiGatewayTest extends TestCase
{
    private ZaiGateway $gateway;

    private ZaiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.zai' => [
            'driver' => 'zai',
            'key' => 'zai_api_key',
        ]]);

        $this->gateway = new ZaiGateway(app(Dispatcher::class), new ServerSentEventsStreamParser);

        $this->provider = $this->app->make(AiManager::class)->textProvider('zai');
    }

    /**
     * Generating text tests
     */
    public function test_generate_text_returns_response()
    {
        Http::fake([
            'api.z.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Hello, world!',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                ],
            ]),
        ]);

        $response = $this->gateway->generateText(
            $this->provider,
            'glm-4.7',
            null,
            []
        );

        $this->assertInstanceOf(TextResponse::class, $response);
        $this->assertEquals('Hello, world!', $response->text);
        $this->assertEquals(10, $response->usage->promptTokens);
        $this->assertEquals(5, $response->usage->completionTokens);
        $this->assertEquals('zai', $response->meta->provider);
        $this->assertEquals('glm-4.7', $response->meta->model);
    }

    public function test_generate_text_with_instructions_includes_system_message()
    {
        Http::fake([
            'api.z.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Response',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                ],
            ]),
        ]);

        $this->gateway->generateText(
            $this->provider,
            'glm-4.7',
            'You are a helpful assistant',
            [
                [
                    'role' => 'user',
                    'content' => 'Hello',
                ],
            ]
        );

        Http::assertSent(function ($request) {
            return isset($request['messages'][0]['role']) &&
                $request['messages'][0]['role'] === 'system' &&
                $request['messages'][0]['content'] === 'You are a helpful assistant' &&
                $request['messages'][1]['role'] === 'user' &&
                $request['messages'][1]['content'] === 'Hello';
        });
    }

    public function test_generate_text_with_options_includes_parameters()
    {
        Http::fake([
            'api.z.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Response',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                ],
            ]),
        ]);

        $options = new TextGenerationOptions(
            maxTokens: 100,
            temperature: 0.7
        );

        $this->gateway->generateText(
            $this->provider,
            'glm-4.7',
            null,
            [],
            options: $options
        );

        Http::assertSent(function ($request) {
            return isset($request['temperature']) &&
                $request['temperature'] === 0.7 &&
                isset($request['max_tokens']) &&
                $request['max_tokens'] === 100;
        });
    }

    public function test_generate_text_with_tools_executes_and_returns_results()
    {
        Http::fake([
            'api.z.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'The weather is sunny',
                            'tool_calls' => [
                                [
                                    'id' => 'call_123',
                                    'function' => [
                                        'name' => 'TestTool',
                                        'arguments' => '{"city":"Tokyo"}',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 20,
                    'completion_tokens' => 10,
                ],
            ]),
        ]);

        $tool = new TestTool('A test tool');

        $invokingCalled = false;
        $invokedCalled = false;

        $this->gateway->onToolInvocation(
            function () use (&$invokingCalled) {
                $invokingCalled = true;
            },
            function () use (&$invokedCalled) {
                $invokedCalled = true;
            }
        );

        $response = $this->gateway->generateText(
            $this->provider,
            'glm-4.7',
            null,
            [],
            tools: [$tool]
        );

        $this->assertTrue($invokingCalled);
        $this->assertTrue($invokedCalled);
        $this->assertCount(1, $response->toolCalls);
        $this->assertCount(1, $response->toolResults);
        $this->assertEquals('call_123', $response->toolCalls[0]->id);
        $this->assertEquals('TestTool', $response->toolCalls[0]->name);
        $this->assertEquals(['city' => 'Tokyo'], $response->toolCalls[0]->arguments);
    }

    public function test_zai_can_generate_text_with_schema()
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'symbol' => ['type' => 'string'],
            ],
        ];

        Http::fake([
            'api.z.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"symbol":"Ag"}',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                ],
            ]),
        ]);

        $response = $this->gateway->generateText(
            $this->provider,
            'glm-5',
            null,
            [],
            schema: $schema
        );

        $this->assertEquals('{"symbol":"Ag"}', $response->text);
        $this->assertEquals('Ag', $response['symbol']);
        $this->assertEquals(10, $response->usage->promptTokens);
        $this->assertEquals(5, $response->usage->completionTokens);

        Http::assertSent(function ($request) {
            return isset($request['response_format']['type']) && $request['response_format']['type'] === 'json_object';
        });
    }

    public function test_zai_can_generate_text_with_schema_and_tools()
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'number' => ['type' => 'integer'],
            ],
        ];

        Http::fake([
            'api.z.ai/*' => Http::response([
                'id' => 'msg_123',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"number":42}',
                            'tool_calls' => [
                                [
                                    'id' => 'call_abc',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'TestTool',
                                        'arguments' => '{}',
                                    ],
                                ],
                            ],
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 15,
                    'completion_tokens' => 10,
                ],
            ]),
        ]);

        $tool = new TestTool('A test tool');

        $invokingCalled = false;
        $invokedCalled = false;

        $this->gateway->onToolInvocation(
            function () use (&$invokingCalled) {
                $invokingCalled = true;
            },
            function () use (&$invokedCalled) {
                $invokedCalled = true;
            }
        );

        $response = $this->gateway->generateText(
            $this->provider,
            'glm-5',
            null,
            [],
            tools: [$tool],
            schema: $schema
        );

        $this->assertTrue($invokingCalled);
        $this->assertTrue($invokedCalled);
        $this->assertEquals('{"number":42}', $response->text);
        $this->assertEquals(42, $response['number']);
        $this->assertCount(1, $response->toolCalls);
        $this->assertEquals('TestTool', $response->toolCalls[0]->name);

        Http::assertSent(function ($request) {
            return isset($request['response_format']['type']) && $request['response_format']['type'] === 'json_object';
        });
    }

    public function test_generate_text_with_rate_limiting_throws_exception()
    {
        Http::fake([
            'api.z.ai/*' => Http::response(
                body: [
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'Hello, world!',
                            ],
                        ],
                    ],
                    'usage' => [
                        'prompt_tokens' => 10,
                        'completion_tokens' => 5,
                    ],
                ],
                status: 429
            ),
        ]);

        $this->expectException(RateLimitedException::class);
        $this->expectExceptionCode(429);

        $this->gateway->generateText(
            $this->provider,
            'glm-4.7',
            null,
            []
        );
    }

    /**
     * Streaming  tests
     */
    public function test_stream_text_yields_text_delta_and_stream_end_events()
    {
        $sseBody = "data: {\"id\":\"msg_1\",\"choices\":[{\"delta\":{\"content\":\"Hello\"}}],\"created\":1000}\n"
            ."data: {\"id\":\"msg_1\",\"choices\":[{\"delta\":{\"content\":\" world\"}}],\"created\":1001}\n"
            ."data: {\"id\":\"msg_1\",\"choices\":[{\"finish_reason\":\"stop\",\"delta\":{}}],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":5}}\n"
            ."data: [DONE]\n";

        Http::fake([
            'api.z.ai/*' => Http::response(
                $sseBody,
                200,
                [
                    'Content-Type' => 'text/event-stream',
                    'Transfer-Encoding' => 'chunked',
                ]
            ),
        ]);

        $events = iterator_to_array($this->gateway->streamText(
            'inv_1',
            $this->provider,
            'glm-4.7',
            null,
            []
        ), false);

        $textDeltas = array_values(array_filter($events, fn ($e) => $e instanceof TextDelta));
        $streamEnds = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd));

        $this->assertCount(2, $textDeltas);
        $this->assertEquals('Hello', $textDeltas[0]->delta);
        $this->assertEquals(' world', $textDeltas[1]->delta);
        $this->assertCount(1, $streamEnds);
        $this->assertEquals(10, $streamEnds[0]->usage->promptTokens);
        $this->assertEquals(5, $streamEnds[0]->usage->completionTokens);
    }

    public function test_stream_text_yields_tool_call_and_tool_result_events()
    {
        $toolArgs1 = '{"city"';
        $toolArgs2 = ':"Tokyo"}';

        $sseBody = 'data: {"id":"msg_1","choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","function":{"name":"TestTool","arguments":"'.addslashes($toolArgs1).'"}}]}}],"created":1000}'."\n"
            .'data: {"id":"msg_1","choices":[{"delta":{"tool_calls":[{"index":0,"id":"","function":{"name":"","arguments":"'.addslashes($toolArgs2).'"}}]}}],"created":1001}'."\n"
            .'data: {"id":"msg_1","choices":[{"finish_reason":"tool_calls","delta":{}}],"usage":{"prompt_tokens":15,"completion_tokens":8}}'."\n"
            ."data: [DONE]\n";

        Http::fake([
            'api.z.ai/*' => Http::response(
                $sseBody,
                200,
                [
                    'Content-Type' => 'text/event-stream',
                    'Transfer-Encoding' => 'chunked',
                ]
            ),
        ]);

        $tool = new TestTool('A test tool');

        $invokingCalled = false;
        $invokedCalled = false;

        $this->gateway->onToolInvocation(
            function () use (&$invokingCalled) {
                $invokingCalled = true;
            },
            function () use (&$invokedCalled) {
                $invokedCalled = true;
            }
        );

        $events = iterator_to_array($this->gateway->streamText(
            'inv_1',
            $this->provider,
            'glm-4.7',
            null,
            [],
            [$tool]
        ), false);

        $this->assertTrue($invokingCalled);
        $this->assertTrue($invokedCalled);

        $toolCalls = array_values(array_filter($events, fn ($e) => $e instanceof ToolCall));
        $toolResults = array_values(array_filter($events, fn ($e) => $e instanceof ToolResult));
        $streamEnds = array_values(array_filter($events, fn ($e) => $e instanceof StreamEnd));

        $this->assertCount(1, $toolCalls);
        $this->assertEquals('call_1', $toolCalls[0]->toolCall->id);
        $this->assertEquals('TestTool', $toolCalls[0]->toolCall->name);
        $this->assertEquals(['city' => 'Tokyo'], $toolCalls[0]->toolCall->arguments);

        $this->assertCount(1, $toolResults);
        $this->assertTrue($toolResults[0]->successful);
        $this->assertNull($toolResults[0]->error);

        $this->assertCount(1, $streamEnds);
        $this->assertEquals('tool_calls', $streamEnds[0]->reason);
    }

    public function test_stream_text_with_options_includes_parameters()
    {
        $sseBody = "data: {\"id\":\"msg_1\",\"choices\":[{\"delta\":{\"content\":\"Hello\"}}],\"created\":1000}\n"
            ."data: {\"id\":\"msg_1\",\"choices\":[{\"finish_reason\":\"stop\",\"delta\":{}}],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":5}}\n"
            ."data: [DONE]\n";

        Http::fake([
            'api.z.ai/*' => Http::response(
                $sseBody,
                200,
                [
                    'Content-Type' => 'text/event-stream',
                    'Transfer-Encoding' => 'chunked',
                ]
            ),
        ]);

        $options = new TextGenerationOptions(
            maxTokens: 100,
            temperature: 0.7
        );

        iterator_to_array($this->gateway->streamText(
            'inv_1',
            $this->provider,
            'glm-4.7',
            null,
            [],
            options: $options
        ));

        Http::assertSent(function ($request) {
            return isset($request['temperature']) &&
                $request['temperature'] === 0.7 &&
                isset($request['max_tokens']) &&
                $request['max_tokens'] === 100;
        });
    }

    public function test_stream_text_with_instructions_includes_system_message()
    {
        $sseBody = "data: {\"id\":\"msg_1\",\"choices\":[{\"delta\":{\"content\":\"Hello\"}}],\"created\":1000}\n"
            ."data: {\"id\":\"msg_1\",\"choices\":[{\"finish_reason\":\"stop\",\"delta\":{}}],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":5}}\n"
            ."data: [DONE]\n";

        Http::fake([
            'api.z.ai/*' => Http::response(
                $sseBody,
                200,
                [
                    'Content-Type' => 'text/event-stream',
                    'Transfer-Encoding' => 'chunked',
                ]
            ),
        ]);

        iterator_to_array($this->gateway->streamText(
            'inv_1',
            $this->provider,
            'glm-4.7',
            'You are a helpful assistant',
            [
                [
                    'role' => 'user',
                    'content' => 'Hello',
                ],
            ]
        ));

        Http::assertSent(function ($request) {
            return isset($request['messages'][0]['role']) &&
                $request['messages'][0]['role'] === 'system' &&
                $request['messages'][0]['content'] === 'You are a helpful assistant' &&
                $request['messages'][1]['role'] === 'user' &&
                $request['messages'][1]['content'] === 'Hello';
        });
    }

    public function test_stream_text_with_rate_limiting_throws_exception()
    {
        $sseBody = "data: {\"id\":\"msg_1\",\"choices\":[{\"delta\":{\"content\":\"Hello\"}}],\"created\":1000}\n"
            ."data: {\"id\":\"msg_1\",\"choices\":[{\"delta\":{\"content\":\" world\"}}],\"created\":1001}\n"
            ."data: {\"id\":\"msg_1\",\"choices\":[{\"finish_reason\":\"stop\",\"delta\":{}}],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":5}}\n"
            ."data: [DONE]\n";

        Http::fake([
            'api.z.ai/*' => Http::response(
                $sseBody,
                429,
                [
                    'Content-Type' => 'text/event-stream',
                    'Transfer-Encoding' => 'chunked',
                ]
            ),
        ]);

        $this->expectException(RateLimitedException::class);
        $this->expectExceptionCode(429);

        iterator_to_array($this->gateway->streamText(
            'inv_1',
            $this->provider,
            'glm-4.7',
            null,
            []
        ));
    }

    /**
     * Shared
     */
    public function test_validate_tool_support_for_glm46_passes()
    {
        $this->expectNotToPerformAssertions();

        $reflection = new ReflectionClass($this->gateway);
        $method = $reflection->getMethod('validateToolCallingSupport');

        $method->invoke($this->gateway, 'glm-4.6');
    }

    public function test_validate_tool_support_for_glm47_passes()
    {
        $this->expectNotToPerformAssertions();

        $reflection = new ReflectionClass($this->gateway);
        $method = $reflection->getMethod('validateToolCallingSupport');

        $method->invoke($this->gateway, 'glm-4.7');
    }

    public function test_validate_tool_support_for_glm45_throws_exception()
    {
        $this->expectException(ModelNotSupportedException::class);
        $this->expectExceptionMessage('Tool calling is only supported on GLM-4.6 and later models');

        $reflection = new ReflectionClass($this->gateway);
        $method = $reflection->getMethod('validateToolCallingSupport');

        $method->invoke($this->gateway, 'glm-4.5');
    }

    public function test_validate_tool_support_for_non_glm_throws_exception()
    {
        $this->expectException(ModelNotSupportedException::class);

        $reflection = new ReflectionClass($this->gateway);
        $method = $reflection->getMethod('validateToolCallingSupport');

        $method->invoke($this->gateway, 'gpt-4');
    }
}
