<?php

namespace Tests\Feature\Providers\Mistral;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;

class ToolCallLoopTest extends MistralTestCase
{
    public function test_tool_calls_trigger_follow_up_request(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'mistral',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode($recorded[1][0]->body(), true);

        $hasAssistantWithToolCalls = false;
        $hasToolResult = false;

        foreach ($followUpBody['messages'] as $message) {
            if ($message['role'] === 'assistant' && ! empty($message['tool_calls'])) {
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
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'mistral',
        );

        $recorded = Http::recorded();

        // ToolUsingAgent has 1 tool + structured output tool = 2 tools
        // maxSteps = round(2 * 1.5) = 3
        // So max 3 requests before stopping (initial + 2 follow-ups)
        $this->assertLessThanOrEqual(3, count($recorded));
    }

    public function test_follow_up_request_includes_original_messages(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_'.uniqid()),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'mistral',
        );

        $recorded = Http::recorded();

        $followUpBody = json_decode($recorded[1][0]->body(), true);

        $userMsg = collect($followUpBody['messages'])->firstWhere('role', 'user');

        $this->assertNotNull($userMsg, 'Follow-up should include original user message');
    }
}
