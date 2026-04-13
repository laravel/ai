<?php

namespace Tests\Feature\Providers\OpenRouter;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\TestCase;

use function Laravel\Ai\agent;

class MessageMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openrouter' => [
            ...config('ai.providers.openrouter'),
            'key' => 'test-key',
        ]]);
    }

    public function test_user_message_maps_to_chat_completions_format(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('Hello')]);

        agent()->prompt('Hello there', provider: 'openrouter');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $userMsg = collect($body['messages'])->firstWhere('role', 'user');

            return $userMsg !== null
                && $userMsg['content'] === 'Hello there';
        });
    }

    public function test_system_instructions_are_sent_as_system_role_message(): void
    {
        Http::fake(['*' => $this->fakeOpenRouterResponse('Hello')]);

        (new AssistantAgent)->prompt('Hello', provider: 'openrouter');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $messages = $body['messages'];

            // System message should be the first message
            return $messages[0]['role'] === 'system'
                && str_contains($messages[0]['content'], 'helpful assistant');
        });
    }

    public function test_tool_result_follow_up_maps_assistant_and_tool_result_messages(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeOpenRouterResponse('The number is 72019'),
            ]),
        ]);

        agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

        $requests = Http::recorded(fn (Request $r) => true);
        $followUpBody = json_decode($requests[1][0]->body(), true);
        $messages = $followUpBody['messages'];

        // Should have assistant message with tool_calls
        $assistantMsg = collect($messages)->firstWhere('role', 'assistant');
        $this->assertNotNull($assistantMsg);
        $this->assertArrayHasKey('tool_calls', $assistantMsg);
        $this->assertSame('FixedNumberGenerator', $assistantMsg['tool_calls'][0]['function']['name']);

        // Should have tool result message
        $toolMsg = collect($messages)->firstWhere('role', 'tool');
        $this->assertNotNull($toolMsg);
        $this->assertSame('call_123', $toolMsg['tool_call_id']);
    }

    protected function fakeToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-tool-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
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
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
        ]);
    }

    protected function fakeOpenRouterResponse(string $text): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'anthropic/claude-sonnet-4.6',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $text,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 1,
                'completion_tokens' => 1,
            ],
        ]);
    }
}
