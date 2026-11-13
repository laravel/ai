<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ReasoningRememberingAgent;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;

function anthropicReasoningResponse(array $content, string $stopReason): PromiseInterface
{
    return Http::response([
        'id' => 'msg_'.uniqid(),
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-5-5',
        'content' => $content,
        'stop_reason' => $stopReason,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function fakeAnthropicReasoningTurns(): void
{
    Http::fake(['api.anthropic.com/*' => Http::sequence([
        anthropicReasoningResponse([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
        ], 'tool_use'),
        anthropicReasoningResponse([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-2'],
            ['type' => 'text', 'text' => 'The number is 72019'],
        ], 'end_turn'),
        anthropicReasoningResponse([['type' => 'text', 'text' => 'Still 72019']], 'end_turn'),
    ])]);
}

/**
 * The messages of the given recorded request, re-encoded so an empty object and an empty list stay distinct.
 *
 * @return list<string>
 */
function anthropicSentMessages(int $request): array
{
    return array_map(
        fn (object $message): string => json_encode($message),
        json_decode(Http::recorded()[$request][0]->body())->messages,
    );
}

beforeEach(function (): void {
    Config::set('ai.conversations.generate_title', false);
    config(['ai.providers.anthropic' => [...config('ai.providers.anthropic'), 'key' => 'test-key']]);
});

test('a follow-up replays the previous turn exactly as that turn sent it, signed thinking included', function (): void {
    fakeAnthropicReasoningTurns();

    $agent = (new ReasoningRememberingAgent)->forUser((object) ['id' => 1]);

    $agent->prompt('Generate a number', provider: 'anthropic');
    $agent->prompt('Again', provider: 'anthropic');

    $lastStepOfFirstTurn = anthropicSentMessages(1);
    $followUp = anthropicSentMessages(2);

    expect(array_slice($followUp, 0, count($lastStepOfFirstTurn)))->toBe($lastStepOfFirstTurn)
        ->and(array_slice($followUp, count($lastStepOfFirstTurn)))->toBe([
            json_encode(['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-2'],
                ['type' => 'text', 'text' => 'The number is 72019'],
            ]]),
            json_encode(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Again']]]),
        ]);
});

test('without the attribute a follow-up replays the previous turn without its thinking', function (): void {
    fakeAnthropicReasoningTurns();

    $agent = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1]);

    $agent->prompt('Generate a number', provider: 'anthropic');
    $agent->prompt('Again', provider: 'anthropic');

    $lastStepOfFirstTurn = anthropicSentMessages(1);
    $followUp = anthropicSentMessages(2);

    expect(array_slice($followUp, 0, count($lastStepOfFirstTurn)))->not->toBe($lastStepOfFirstTurn)
        ->and(implode('', $followUp))->not->toContain('"thinking"');
});
