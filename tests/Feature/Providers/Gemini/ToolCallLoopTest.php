<?php

namespace Tests\Feature\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ToolUsingAgent;

class ToolCallLoopTest extends GeminiTestCase
{
    public function test_tool_calls_trigger_follow_up_request(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a random number',
            provider: 'gemini',
        );

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpContents = $recorded[1][0]->data()['contents'];

        $hasModelWithFunctionCall = false;
        $hasFunctionResponse = false;

        foreach ($followUpContents as $content) {
            if ($content['role'] === 'model') {
                foreach ($content['parts'] as $part) {
                    if (isset($part['functionCall'])) {
                        $hasModelWithFunctionCall = true;
                    }
                }
            }

            if ($content['role'] === 'user') {
                foreach ($content['parts'] ?? [] as $part) {
                    if (isset($part['functionResponse'])) {
                        $hasFunctionResponse = true;
                    }
                }
            }
        }

        $this->assertTrue($hasModelWithFunctionCall, 'Follow-up request should include model message with functionCall');
        $this->assertTrue($hasFunctionResponse, 'Follow-up request should include user message with functionResponse');
    }

    public function test_max_steps_limits_tool_call_depth(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeUniqueToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $response = (new ToolUsingAgent(fixed: true))->prompt(
            'Generate numbers',
            provider: 'gemini',
        );

        $recorded = Http::recorded();

        // ToolUsingAgent has 1 tool, maxSteps = count($tools) * 2 = 2
        // So max 3 requests (initial + 2 follow-ups)
        $this->assertLessThanOrEqual(3, count($recorded));
    }

    public function test_function_response_includes_id_for_gemini_3(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                $this->fakeToolCallResponse('FixedNumberGenerator', 'call_abc123'),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpContents = $recorded[1][0]->data()['contents'];

        $functionResponsePart = null;

        foreach ($followUpContents as $content) {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionResponse'])) {
                    $functionResponsePart = $part['functionResponse'];
                }
            }
        }

        $this->assertNotNull($functionResponsePart, 'Follow-up should include functionResponse');
        $this->assertSame('FixedNumberGenerator', $functionResponsePart['name']);
        $this->assertArrayHasKey('id', $functionResponsePart);
        $this->assertArrayHasKey('response', $functionResponsePart);
        $this->assertArrayHasKey('name', $functionResponsePart['response']);
        $this->assertArrayHasKey('content', $functionResponsePart['response']);
    }

    public function test_parallel_function_calls_preserve_unique_ids(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response([
                    'candidates' => [[
                        'content' => [
                            'parts' => [
                                ['functionCall' => ['id' => 'call_1', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                                ['functionCall' => ['id' => 'call_2', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                            ],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
                ]),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate two', provider: 'gemini');

        $recorded = Http::recorded();

        $this->assertCount(2, $recorded);

        $followUpContents = $recorded[1][0]->data()['contents'];

        $functionResponseIds = [];

        foreach ($followUpContents as $content) {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionResponse']['id'])) {
                    $functionResponseIds[] = $part['functionResponse']['id'];
                }
            }
        }

        $this->assertCount(2, $functionResponseIds);
        $this->assertContains('call_1', $functionResponseIds);
        $this->assertContains('call_2', $functionResponseIds);
    }

    public function test_thinking_parts_are_excluded_from_tool_call_continuation(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response([
                    'candidates' => [[
                        'content' => [
                            'parts' => [
                                ['text' => 'Let me think about this...', 'thought' => true],
                                ['functionCall' => ['id' => 'call_1', 'name' => 'FixedNumberGenerator', 'args' => (object) []]],
                            ],
                            'role' => 'model',
                        ],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
                ]),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt('Generate', provider: 'gemini');

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        $followUpContents = $recorded[1][0]->data()['contents'];

        foreach ($followUpContents as $content) {
            if ($content['role'] === 'model') {
                foreach ($content['parts'] as $part) {
                    $this->assertFalse(
                        $part['thought'] ?? false,
                        'Thinking parts should be excluded from tool call continuation'
                    );
                }
            }
        }
    }

    /**
     * Create a tool call response with unique IDs for use in sequences.
     */
    protected function fakeUniqueToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'id' => 'call_'.uniqid(),
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
}
