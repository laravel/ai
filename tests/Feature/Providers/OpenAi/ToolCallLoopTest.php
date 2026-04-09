<?php

namespace Tests\Feature\Providers\OpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;
use Tests\TestCase;

class ToolCallLoopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'key' => 'test-key',
        ]]);
    }

    public function test_tool_calls_trigger_follow_up_request(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeOpenAiResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'openai',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpBody = json_decode($recorded[1][0]->body(), true);

        $this->assertArrayHasKey('previous_response_id', $followUpBody);

        $hasFunctionCallOutput = false;

        foreach ($followUpBody['input'] as $item) {
            if (($item['type'] ?? '') === 'function_call_output') {
                $hasFunctionCallOutput = true;
            }
        }

        $this->assertTrue($hasFunctionCallOutput, 'Follow-up request should include function_call_output');
    }

    public function test_max_steps_limits_tool_call_depth(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeOpenAiResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'openai',
        );

        $recorded = Http::recorded();

        // ToolUsingAgent has 1 tool + structured output tool = 2 tools
        // maxSteps = round(2 * 1.5) = 3
        // So max 3 requests before stopping (initial + 2 follow-ups)
        $this->assertLessThanOrEqual(3, count($recorded));
    }

    protected function fakeOpenAiResponse(string $text): PromiseInterface
    {
        return Http::response([
            'id' => 'resp_123',
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [[
                'type' => 'message',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => $text,
                ]],
            ]],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 1,
            ],
        ]);
    }

    protected function fakeUniqueToolCallResponse(): PromiseInterface
    {
        $id = uniqid();

        return Http::response([
            'id' => 'resp_tool_'.$id,
            'status' => 'completed',
            'model' => 'gpt-5.4',
            'output' => [[
                'type' => 'function_call',
                'id' => 'fc_'.$id,
                'call_id' => 'call_'.$id,
                'name' => 'FixedNumberGenerator',
                'arguments' => '{}',
                'status' => 'completed',
            ]],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ]);
    }
}
