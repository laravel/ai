<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

function fakeMistralLibrarySearchCall(string $id = 'call-lib'): array
{
    return [
        'id' => $id,
        'type' => 'function',
        'function' => ['name' => 'library_search', 'arguments' => '{"query": "Valkey"}'],
        'index' => 0,
        'metadata' => ['tool_type' => 'rag'],
    ];
}

function fakeMistralLibrarySearchResult(string $id = 'call-lib'): array
{
    return [
        'role' => 'tool',
        'index' => 1,
        'tool_call_id' => $id,
        'content' => '{"ref-1": {"title": "roadmap.txt", "snippets": ["Valkey is on the roadmap."]}}',
    ];
}

function fakeMistralMultiCompletionResponse(array $messages, string $finishReason = 'stop'): array
{
    return [
        'id' => 'chatcmpl-123',
        'object' => 'chat.multi_completion',
        'model' => 'mistral-medium-latest',
        'choices' => [['index' => 0, 'finish_reason' => $finishReason, 'messages' => $messages]],
        'usage' => ['prompt_tokens' => 1950, 'completion_tokens' => 89, 'total_tokens' => 2039],
    ];
}

test('file search options return library ids', function () {
    expect(Ai::storeProvider('mistral')->fileSearchToolOptions(new FileSearch(['lib-1', 'lib-2'])))
        ->toBe(['library_ids' => ['lib-1', 'lib-2']]);
});

test('file search metadata filters throw an exception', function () {
    $search = new FileSearch(['lib-1'], where: ['company' => 'laravel']);

    expect(fn () => Ai::storeProvider('mistral')->fileSearchToolOptions($search))
        ->toThrow(InvalidArgumentException::class, 'Mistral does not support file search metadata filters.');
});

test('file search sends a document library tool and reads the multi completion answer', function () {
    Http::fake([
        'api.mistral.ai/v1/chat/completions' => Http::response(fakeMistralMultiCompletionResponse([
            ['role' => 'assistant', 'index' => 0, 'content' => '', 'tool_calls' => [fakeMistralLibrarySearchCall()]],
            fakeMistralLibrarySearchResult(),
            ['role' => 'assistant', 'index' => 2, 'content' => [
                ['type' => 'text', 'text' => 'Valkey is mentioned'],
                ['type' => 'reference', 'reference_ids' => ['ref-1']],
                ['type' => 'text', 'text' => '.'],
            ]],
        ])),
    ]);

    $response = agent(tools: [new FileSearch(['lib-123'])])->prompt('Is Valkey mentioned?', provider: 'mistral');

    expect($response->text)->toBe('Valkey is mentioned.')
        ->and($response->toolCalls)->toBeEmpty()
        ->and($response->usage->promptTokens)->toBe(1950);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['tools'] === [['type' => 'document_library', 'library_ids' => ['lib-123']]]);
});

test('client tool calls alongside a library search trigger the tool loop', function () {
    Http::fake([
        'api.mistral.ai/v1/chat/completions' => Http::sequence([
            Http::response(fakeMistralMultiCompletionResponse([
                ['role' => 'assistant', 'index' => 0, 'content' => '', 'tool_calls' => [
                    fakeMistralLibrarySearchCall(),
                    ['id' => 'call-fn', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'], 'index' => 1],
                ]],
                fakeMistralLibrarySearchResult(),
            ], 'tool_calls')),
            Http::response(fakeMistralMultiCompletionResponse([
                ['role' => 'assistant', 'index' => 0, 'content' => 'The number is 72019.'],
            ])),
        ]),
    ]);

    $response = agent(tools: [new FileSearch(['lib-123']), new FixedNumberGenerator])
        ->prompt('Generate a number', provider: 'mistral');

    expect($response->text)->toBe('The number is 72019.')
        ->and($response->toolCalls->pluck('name')->all())->toBe(['FixedNumberGenerator']);

    $followUp = json_decode(Http::recorded()[1][0]->body(), true);

    expect(collect($followUp['messages'])->where('role', 'tool')->pluck('tool_call_id')->all())->toBe(['call-fn']);
});

test('streamed library search results are not emitted as text', function () {
    Http::fake([
        'api.mistral.ai/v1/chat/completions' => Http::response(
            body: $this->ssePayload([
                ['model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'index' => 0, 'content' => ''], 'finish_reason' => null]]],
                ['model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['index' => 0, 'tool_calls' => [fakeMistralLibrarySearchCall()]], 'finish_reason' => 'tool_calls']]],
                ['model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => fakeMistralLibrarySearchResult(), 'finish_reason' => null]]],
                ['model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'index' => 2, 'content' => 'Valkey is '], 'finish_reason' => null]]],
                ['model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['index' => 2, 'content' => 'mentioned.'], 'finish_reason' => 'stop']]],
            ]),
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $stream = agent(tools: [new FileSearch(['lib-123'])])->stream('Is Valkey mentioned?', provider: 'mistral');

    $events = collect(iterator_to_array($stream, false));

    expect($events->whereInstanceOf(TextDelta::class)->pluck('delta')->all())->toBe(['Valkey is ', 'mentioned.'])
        ->and($events->whereInstanceOf(ToolCallEvent::class))->toBeEmpty();

    Http::assertSentCount(1);
});

test('streamed client tool calls alongside a library search trigger the tool loop', function () {
    Http::fake([
        'api.mistral.ai/v1/chat/completions' => Http::sequence([
            Http::response(
                body: $this->ssePayload([
                    ['model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'index' => 0, 'content' => ''], 'finish_reason' => null]]],
                    ['model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['index' => 0, 'tool_calls' => [
                        fakeMistralLibrarySearchCall(),
                        ['id' => 'call-fn', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'], 'index' => 1],
                    ]], 'finish_reason' => 'tool_calls']]],
                    ['model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => fakeMistralLibrarySearchResult(), 'finish_reason' => null]]],
                ]),
                headers: ['Content-Type' => 'text/event-stream'],
            ),
            Http::response(
                body: $this->ssePayload([
                    ['model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019.'], 'finish_reason' => 'stop']]],
                ]),
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]),
    ]);

    $stream = agent(tools: [new FileSearch(['lib-123']), new FixedNumberGenerator])
        ->stream('Generate a number', provider: 'mistral');

    $events = collect(iterator_to_array($stream, false));

    expect($events->whereInstanceOf(ToolCallEvent::class)->map->toolCall->pluck('name')->values()->all())->toBe(['FixedNumberGenerator'])
        ->and($stream->text)->toBe('The number is 72019.');
});
