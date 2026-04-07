<?php

namespace Tests\Feature\Providers\Anthropic;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;

class ToolCallLoopTest extends AnthropicTestCase
{
    public function test_tool_calls_trigger_follow_up_request(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'anthropic',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = $recorded[1][0]->data();

        $hasAssistantWithToolUse = false;
        $hasToolResult = false;

        foreach ($followUpBody['messages'] as $message) {
            if ($message['role'] === 'assistant') {
                foreach ($message['content'] as $block) {
                    if (($block['type'] ?? '') === 'tool_use') {
                        $hasAssistantWithToolUse = true;
                    }
                }
            }

            if ($message['role'] === 'user') {
                foreach ($message['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $hasToolResult = true;
                    }
                }
            }
        }

        $this->assertTrue($hasAssistantWithToolUse, 'Follow-up request should include assistant message with tool_use block');
        $this->assertTrue($hasToolResult, 'Follow-up request should include user message with tool_result block');
    }

    public function test_max_steps_limits_tool_call_depth(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'anthropic',
        );

        $recorded = Http::recorded();

        // ToolUsingAgent has 1 tool + structured output tool = 2 tools
        // maxSteps = round(2 * 1.5) = 3
        // So max 3 requests before stopping (initial + 2 follow-ups)
        $this->assertLessThanOrEqual(3, count($recorded));
    }

    /**
     * Create a tool call response with unique IDs for use in sequences.
     */
    protected function fakeUniqueToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'msg_tool_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_'.uniqid(),
                'name' => 'FixedNumberGenerator',
                'input' => (object) [],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
    }
}
