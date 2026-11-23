<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Tests\Fixtures\Agents\RememberingToolUsingAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\RandomNumberGenerator;

function anthropicThinkingResponse(array $content, string $stopReason): PromiseInterface
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

function fakeAnthropicThinkingTurns(array $firstStep = [
    ['type' => 'thinking', 'thinking' => 'Calling the tool.', 'signature' => 'sig-1'],
    ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
]): void {
    Http::fake(['api.anthropic.com/*' => Http::sequence([
        anthropicThinkingResponse($firstStep, 'tool_use'),
        anthropicThinkingResponse([
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-2'],
            ['type' => 'text', 'text' => 'The number is 72019'],
        ], 'end_turn'),
        anthropicThinkingResponse([['type' => 'text', 'text' => 'Still 72019']], 'end_turn'),
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

function changeableThinkingAgent(): RememberingToolUsingAgent
{
    return new class extends RememberingToolUsingAgent
    {
        public string $prompt = 'Always use the available tool to generate a number.';

        public array $tools = [];

        public function instructions(): string
        {
            return $this->prompt;
        }

        public function tools(): iterable
        {
            return [new FixedNumberGenerator, ...$this->tools];
        }
    };
}

beforeEach(function (): void {
    Config::set('ai.conversations.generate_title', false);
    config(['ai.providers.anthropic' => [...config('ai.providers.anthropic'), 'key' => 'test-key']]);
});

test('a follow-up replays the previous turn exactly as its last step sent it, signed thinking included', function (): void {
    fakeAnthropicThinkingTurns();

    $agent = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1]);

    $agent->prompt('Generate a number', provider: 'anthropic');
    $agent->prompt('Again', provider: 'anthropic');

    $lastStepOfFirstTurn = anthropicSentMessages(1);
    $followUp = anthropicSentMessages(2);

    expect($lastStepOfFirstTurn[1])->toContain('"signature":"sig-1"')
        ->and(array_slice($followUp, 0, count($lastStepOfFirstTurn)))->toBe($lastStepOfFirstTurn)
        ->and(array_slice($followUp, count($lastStepOfFirstTurn)))->toBe([
            json_encode(['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'The number is 72019']]]),
            json_encode(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Again']]]),
        ]);
});

test('a follow-up replays a later step\'s thinking whatever the key order of the blocks before it', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::sequence([
        anthropicThinkingResponse([
            ['signature' => 'sig-1', 'thinking' => 'Calling the tool.', 'type' => 'thinking'],
            ['text' => 'Let me check.', 'type' => 'text'],
            ['input' => (object) [], 'name' => 'FixedNumberGenerator', 'id' => 'toolu_1', 'type' => 'tool_use'],
        ], 'tool_use'),
        anthropicThinkingResponse([
            ['type' => 'thinking', 'thinking' => 'Calling it again.', 'signature' => 'sig-2'],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
        ], 'tool_use'),
        anthropicThinkingResponse([['type' => 'text', 'text' => 'The number is 72019']], 'end_turn'),
        anthropicThinkingResponse([['type' => 'text', 'text' => 'Still 72019']], 'end_turn'),
    ])]);

    $agent = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1]);

    $agent->prompt('Generate a number', provider: 'anthropic');
    $agent->prompt('Again', provider: 'anthropic');

    expect(anthropicSentMessages(3)[3])->toContain('"signature":"sig-2"');
});

test('a follow-up replays a streamed turn exactly as its last step sent it, signed thinking included', function (): void {
    $stream = fn (array $events): PromiseInterface => Http::response($this->ssePayload([$this->messageStart(), ...$events]), 200, ['Content-Type' => 'text/event-stream']);

    Http::fake(['api.anthropic.com/*' => Http::sequence([
        $stream([
            $this->contentBlockStart(0, ['type' => 'thinking', 'thinking' => '']),
            $this->contentBlockDelta(0, ['type' => 'signature_delta', 'signature' => 'sig-']),
            $this->contentBlockDelta(0, ['type' => 'signature_delta', 'signature' => '1']),
            $this->contentBlockStop(0),
            $this->contentBlockStart(1, ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []]),
            $this->contentBlockDelta(1, ['type' => 'input_json_delta', 'partial_json' => '{}']),
            $this->contentBlockStop(1),
            $this->messageDelta('tool_use', 5),
        ]),
        $stream([
            $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
            $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'The number is 72019']),
            $this->contentBlockStop(0),
            $this->messageDelta('end_turn', 5),
        ]),
        anthropicThinkingResponse([['type' => 'text', 'text' => 'Still 72019']], 'end_turn'),
    ])]);

    $agent = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1]);

    $agent->stream('Generate a number', provider: 'anthropic')->each(fn () => null);
    $agent->prompt('Again', provider: 'anthropic');

    $lastStepOfFirstTurn = anthropicSentMessages(1);

    expect($lastStepOfFirstTurn[1])->toContain('"signature":"sig-1"')
        ->and(array_slice(anthropicSentMessages(2), 0, count($lastStepOfFirstTurn)))->toBe($lastStepOfFirstTurn);
});

test('a follow-up drops the previous turn\'s thinking once the agent\'s :dataset changed', function (Closure $change): void {
    fakeAnthropicThinkingTurns();

    $agent = changeableThinkingAgent()->forUser((object) ['id' => 1]);

    $agent->prompt('Generate a number', provider: 'anthropic');

    $change($agent);

    $agent->prompt('Again', provider: 'anthropic');

    expect(anthropicSentMessages(2)[1])->toBe(json_encode(['role' => 'assistant', 'content' => [
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
    ]]));
})->with([
    'instructions' => [fn ($agent) => $agent->prompt = 'Today is a different day.'],
    'tools' => [fn ($agent) => $agent->tools = [new RandomNumberGenerator]],
]);

test('a follow-up drops the previous turn\'s thinking once earlier turns fall out of the window', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::sequence([
        anthropicThinkingResponse([['type' => 'text', 'text' => 'Hello']], 'end_turn'),
        anthropicThinkingResponse([
            ['type' => 'thinking', 'thinking' => 'Calling the tool.', 'signature' => 'sig-1'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
        ], 'tool_use'),
        anthropicThinkingResponse([['type' => 'text', 'text' => 'The number is 72019']], 'end_turn'),
        anthropicThinkingResponse([['type' => 'text', 'text' => 'Still 72019']], 'end_turn'),
    ])]);

    $agent = (new class extends RememberingToolUsingAgent
    {
        protected function maxConversationMessages(): int
        {
            return 3;
        }
    })->forUser((object) ['id' => 1]);

    $agent->prompt('Hi', provider: 'anthropic');
    $agent->prompt('Generate a number', provider: 'anthropic');
    $agent->prompt('Again', provider: 'anthropic');

    expect(implode('', anthropicSentMessages(3)))->toContain('toolu_1')
        ->not->toContain('"thinking"');
});

test('a step that thinks again after its first content block keeps no thinking for later turns', function (): void {
    fakeAnthropicThinkingTurns([
        ['type' => 'thinking', 'thinking' => 'Calling the tool.', 'signature' => 'sig-1'],
        ['type' => 'text', 'text' => 'Let me check.'],
        ['type' => 'thinking', 'thinking' => 'Now calling it.', 'signature' => 'sig-1b'],
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
    ]);

    $agent = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1]);

    $agent->prompt('Generate a number', provider: 'anthropic');
    $agent->prompt('Again', provider: 'anthropic');

    expect(implode('', anthropicSentMessages(2)))->toContain('toolu_1')
        ->not->toContain('"thinking"');
});

test('a follow-up of a structured agent never replays the synthetic structured output call', function (): void {
    config(['ai.providers.anthropic.use_native_structured_output' => false]);

    Http::fake(['api.anthropic.com/*' => Http::sequence([
        anthropicThinkingResponse([
            ['type' => 'thinking', 'thinking' => 'Calling the tool.', 'signature' => 'sig-1'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
        ], 'tool_use'),
        anthropicThinkingResponse([
            ['type' => 'thinking', 'thinking' => 'Answering.', 'signature' => 'sig-2'],
            ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'output_structured_data', 'input' => ['number' => 72019]],
        ], 'tool_use'),
        anthropicThinkingResponse([
            ['type' => 'tool_use', 'id' => 'toolu_3', 'name' => 'output_structured_data', 'input' => ['number' => 72019]],
        ], 'tool_use'),
    ])]);

    $agent = (new class extends RememberingToolUsingAgent implements HasStructuredOutput
    {
        public function schema(JsonSchema $schema): array
        {
            return ['number' => $schema->integer()->required()];
        }
    })->forUser((object) ['id' => 1]);

    $agent->prompt('Generate a number', provider: 'anthropic');
    $agent->prompt('Again', provider: 'anthropic');

    expect(implode('', anthropicSentMessages(2)))->toContain('"signature":"sig-1"')
        ->not->toContain('toolu_2')
        ->not->toContain('sig-2');
});
