<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Tools\ToolResponse;
use Tests\Fixtures\Agents\ToolUsingAgent;
use Tests\Fixtures\Tools\FileReturningTool;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

test('tool calls trigger follow up request', function (): void {
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

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id');

    $hasToolOutput = false;

    foreach ($followUpBody['input'] as $item) {
        if (($item['type'] ?? '') === 'function_call_output') {
            $hasToolOutput = true;
        }
    }

    expect($hasToolOutput)->toBeTrue('Follow-up request should include function_call_output');
});

test('max steps limits tool call depth', function (): void {
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

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('follow up request preserves tools', function (): void {
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

    $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('tools')
        ->and($followUpBody['tools'])->not->toBeEmpty();
});

test('files a tool returns are sent with its output when continuing a stored response', function (): void {
    Http::fake([
        'api.x.ai/*' => Http::sequence([
            $this->fakeToolCallResponse('FileReturningTool'),
            $this->fakeTextResponse('The chart is red.'),
        ]),
    ]);

    agent('Hi.', [], [
        new FileReturningTool(ToolResponse::make('Here is the chart.', [Image::fromPath(__DIR__.'/../../../Fixtures/Images/red.png')])),
    ])->prompt('Describe the sales chart', provider: 'xai');

    $output = collect(Http::recorded()[1][0]->data()['input'])->firstWhere('type', 'function_call_output')['output'];

    expect($output[0])->toBe(['type' => 'input_text', 'text' => 'Here is the chart.'])
        ->and($output[1]['image_url'])->toStartWith('data:image/png;base64,');
});
