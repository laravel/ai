<?php

namespace Tests\Feature\Providers\Groq;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;
use Tests\TestCase;

class ToolCallLoopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.groq' => [
            ...config('ai.providers.groq'),
            'key' => 'test-key',
        ]]);
    }

    public function test_tool_calls_trigger_follow_up_request(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeGroqResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'groq',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode($recorded[1][0]->body(), true);

        $hasAssistantWithToolCalls = false;
        $hasToolResult = false;

        foreach ($followUpBody['messages'] as $message) {
            if ($message['role'] === 'assistant' && isset($message['tool_calls'])) {
                $hasAssistantWithToolCalls = true;
            }

            if ($message['role'] === 'tool') {
                $hasToolResult = true;
            }
        }

        $this->assertTrue($hasAssistantWithToolCalls, 'Follow-up request should include assistant message with tool_calls');
        $this->assertTrue($hasToolResult, 'Follow-up request should include tool result message');
    }

    public function test_max_steps_limits_tool_call_depth(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeGroqResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'groq',
        );

        $recorded = Http::recorded();

        // ToolUsingAgent has 1 tool + structured output tool = 2 tools
        // maxSteps = round(2 * 1.5) = 3
        // So max 3 requests before stopping (initial + 2 follow-ups)
        $this->assertLessThanOrEqual(3, count($recorded));
    }

    protected function fakeGroqResponse(string $text): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-123',
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
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

    protected function fakeUniqueToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'chatcmpl-tool-'.uniqid(),
            'object' => 'chat.completion',
            'model' => 'openai/gpt-oss-20b',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_'.uniqid(),
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
}
