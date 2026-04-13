<?php

namespace Tests\Feature\Providers\OpenRouter;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Feature\Tools\FixedNumberGenerator;
use Tests\TestCase;

use function Laravel\Ai\agent;

class ToolCallLoopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openrouter' => [
            ...config('ai.providers.openrouter'),
            'key' => 'test-key',
        ]]);
    }

    public function test_tool_calls_trigger_follow_up_request(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        $response = agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

        $this->assertSame('The number is 72019', $response->text);

        $requests = Http::recorded(fn (Request $r) => true);
        $this->assertGreaterThanOrEqual(2, count($requests));

        $followUpBody = json_decode($requests[1][0]->body(), true);
        $messages = $followUpBody['messages'];

        // Verify the follow-up includes assistant message with tool_calls and tool result message
        $assistantMsg = collect($messages)->firstWhere('role', 'assistant');
        $this->assertNotNull($assistantMsg);
        $this->assertArrayHasKey('tool_calls', $assistantMsg);

        $toolMsg = collect($messages)->firstWhere('role', 'tool');
        $this->assertNotNull($toolMsg);
        $this->assertSame('call_123', $toolMsg['tool_call_id']);
    }

    public function test_max_steps_limits_tool_call_depth(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeToolCallResponse(),
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('Done'),
            ]),
        ]);

        $agent = new #[MaxSteps(3)] class implements Agent, HasTools
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
        };

        $response = $agent->prompt('Keep calling tools', provider: 'openrouter');

        $requests = Http::recorded(fn (Request $r) => true);

        // Should have been limited to 3 total requests
        $this->assertLessThanOrEqual(3, count($requests));
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

    protected function fakeTextResponse(string $text): PromiseInterface
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
