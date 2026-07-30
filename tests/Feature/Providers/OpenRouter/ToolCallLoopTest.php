<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function (): void {
    Http::fake([
        '*' => Http::sequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('The number is 72019'),
        ]),
    ]);

    $response = agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

    expect($response->text)->toBe('The number is 72019');

    $requests = Http::recorded(fn (Request $r): true => true);
    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode((string) $requests[1][0]->body(), true);
    $messages = $followUpBody['messages'];

    $assistantMsg = collect($messages)->firstWhere('role', 'assistant');
    expect($assistantMsg)->not->toBeNull()
        ->and($assistantMsg)->toHaveKey('tool_calls');

    $toolMsg = collect($messages)->firstWhere('role', 'tool');
    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg['tool_call_id'])->toBe('call_123');
});

test('max steps limits tool call depth', function (): void {
    Http::fake([
        '*' => Http::sequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('Done'),
        ]),
    ]);

    $agent = new #[MaxSteps(3)] class implements Agent, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function tools(Lab|string $provider): iterable
        {
            return [new FixedNumberGenerator];
        }
    };

    $agent->prompt('Keep calling tools', provider: 'openrouter');

    $requests = Http::recorded(fn (Request $r): true => true);

    expect(count($requests))->toBeLessThanOrEqual(3);
});

test('multi step tool loop returns accumulated response shape', function (): void {
    Http::fake([
        '*' => Http::sequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('Done'),
        ]),
    ]);

    $response = (new MultiStepToolAgent)->prompt('Generate numbers', provider: 'openrouter');

    expect((string) $response)->toBe('Done')
        ->and($response->messages)->toHaveCount(5)
        ->and($response->steps)->toHaveCount(3)
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->usage->promptTokens)->toBe(21)
        ->and($response->usage->completionTokens)->toBe(11);
});

test('unregistered tool call throws no such tool exception', function (): void {
    Http::fake([
        '*' => Http::response([
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
                        'function' => ['name' => 'UnregisteredTool', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ]),
    ]);

    expect(fn (): AgentResponse => agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter'))
        ->toThrow(NoSuchToolException::class);
});
