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

test('tool-loop follow-up uses the original request model, not the response model', function () {
    Http::fake([
        '*' => Http::sequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('Done'),
        ]),
    ]);

    $response = agent(tools: [new FixedNumberGenerator])->prompt(
        'Give me a number',
        provider: 'openrouter',
        model: 'anthropic/claude-sonnet-4',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);

    expect($followUp['model'])->toBe('anthropic/claude-sonnet-4')
        ->and($followUp['model'])->not->toBe('anthropic/claude-sonnet-4.6')
        ->and($response->meta->model)->toBe('anthropic/claude-sonnet-4.6');
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

    $agent->prompt('Keep calling tools', provider: 'openrouter');

    $requests = Http::recorded(fn (Request $r) => true);

    expect(count($requests))->toBeLessThanOrEqual(3);
});
