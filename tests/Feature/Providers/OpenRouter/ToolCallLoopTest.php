<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('The number is 72019'),
        ]),
    ]);

    $response = agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

    expect($response->text)->toBe('The number is 72019');

    $requests = Http::recorded(fn (Request $r) => true);
    expect(count($requests))->toBeGreaterThanOrEqual(2);

    $followUpBody = json_decode($requests[1][0]->body(), true);
    $messages = $followUpBody['messages'];

    $assistantMsg = collect($messages)->firstWhere('role', 'assistant');
    expect($assistantMsg)->not->toBeNull()
        ->and($assistantMsg)->toHaveKey('tool_calls');

    $toolMsg = collect($messages)->firstWhere('role', 'tool');
    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg['tool_call_id'])->toBe('call_123');
});

test('max steps limits tool call depth', function () {
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

        public function tools(): iterable
        {
            return [new FixedNumberGenerator];
        }
    };

    $response = $agent->prompt('Keep calling tools', provider: 'openrouter');

    $requests = Http::recorded(fn (Request $r) => true);

    expect($requests)->toHaveCount(4)
        ->and($response->text)->toBe('Done');
});
