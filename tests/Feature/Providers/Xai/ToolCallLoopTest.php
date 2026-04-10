<?php

namespace Tests\Feature\Providers\Xai;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;

class ToolCallLoopTest extends XaiTestCase
{
    public function test_tool_calls_trigger_follow_up_request(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'xai',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode($recorded[1][0]->body(), true);

        $this->assertArrayHasKey('previous_response_id', $followUpBody);

        $hasToolOutput = false;

        foreach ($followUpBody['input'] as $item) {
            if (($item['type'] ?? '') === 'function_call_output') {
                $hasToolOutput = true;
            }
        }

        $this->assertTrue($hasToolOutput, 'Follow-up request should include function_call_output');
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
            provider: 'xai',
        );

        $recorded = Http::recorded();

        // ToolUsingAgent has 1 tool + structured output tool = 2 tools
        // maxSteps = round(2 * 1.5) = 3
        // So max 3 requests before stopping (initial + 2 follow-ups)
        $this->assertLessThanOrEqual(3, count($recorded));
    }

    public function test_follow_up_request_preserves_tools(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'xai',
        );

        $recorded = Http::recorded();

        $followUpBody = json_decode($recorded[1][0]->body(), true);

        $this->assertArrayHasKey('tools', $followUpBody);
        $this->assertNotEmpty($followUpBody['tools']);
    }
}
