<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;

function openAiReasoningToolTurn(): PromiseInterface
{
    return Http::response([
        'id' => 'resp_tool_1',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'],
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}', 'status' => 'completed'],
        ],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function openAiTextTurn(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'resp_'.uniqid(),
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

beforeEach(function (): void {
    Config::set('ai.conversations.generate_title', false);
    config(['ai.providers.openai' => [...config('ai.providers.openai'), 'key' => 'test-key']]);
});

test('a stored reasoning turn replays its tool call without the item id its reasoning no longer backs', function (): void {
    Http::fake(['api.openai.com/*' => Http::sequence([
        openAiReasoningToolTurn(),
        openAiTextTurn('The number is 72019'),
        openAiTextTurn('Still 72019'),
    ])]);

    $agent = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1]);

    $agent->prompt('Generate a number', provider: 'openai');

    // The stored turn no longer carries the reasoning item, so the replay must not name the function call item either...
    $agent->prompt('Again', provider: 'openai');

    $replayed = collect(Http::recorded())->last()[0];

    $input = collect(json_decode($replayed->body(), true)['input']);

    $functionCalls = $input->where('type', 'function_call')->values();

    expect($functionCalls)->toHaveCount(1)
        ->and($functionCalls[0])->toMatchArray(['call_id' => 'call_1', 'name' => 'FixedNumberGenerator'])
        ->and($functionCalls[0])->not->toHaveKey('id')
        ->and($input->where('type', 'reasoning'))->toBeEmpty();
});

test('a live reasoning turn still names its function call item within the same run', function (): void {
    Http::fake(['api.openai.com/*' => Http::sequence([
        openAiReasoningToolTurn(),
        openAiTextTurn('The number is 72019'),
    ])]);

    config(['ai.providers.openai' => [...config('ai.providers.openai'), 'store' => false]]);

    (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->prompt('Generate a number', provider: 'openai');

    $followUp = collect(Http::recorded())->last()[0];

    $input = collect(json_decode($followUp->body(), true)['input']);

    $reasoningIndex = $input->search(fn (array $item): bool => ($item['type'] ?? '') === 'reasoning');
    $callIndex = $input->search(fn (array $item): bool => ($item['id'] ?? '') === 'fc_1');

    expect($reasoningIndex)->not->toBeFalse()
        ->and($callIndex)->toBe($reasoningIndex + 1);
});
