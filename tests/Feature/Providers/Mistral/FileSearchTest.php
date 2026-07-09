<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

function fakeMistralConversationResponse(string|array $content = 'Valkey is mentioned.'): array
{
    return [
        'object' => 'conversation.response',
        'conversation_id' => 'conv-123',
        'outputs' => [
            [
                'object' => 'entry',
                'type' => 'tool.execution',
                'name' => 'document_library',
                'arguments' => '{}',
            ],
            [
                'object' => 'entry',
                'type' => 'message.output',
                'role' => 'assistant',
                'model' => 'mistral-medium-latest',
                'content' => $content,
            ],
        ],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
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

test('attachments with file search throw an exception', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse()),
    ]);

    expect(fn () => agent(tools: [new FileSearch(['lib-123'])])
        ->prompt('Describe this', attachments: [new RemoteImage('https://example.com/image.png')], provider: 'mistral'))
        ->toThrow(RuntimeException::class, 'Mistral does not support attachments when using file search.');
});

test('prompts with file search route to the conversations api', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse()),
    ]);

    $response = agent(instructions: 'Answer from the documents.', tools: [new FileSearch(['lib-123'])])
        ->prompt('Is Valkey mentioned?', provider: 'mistral');

    expect((string) $response)->toBe('Valkey is mentioned.');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://api.mistral.ai/v1/conversations'
            && $body['model'] === 'mistral-medium-latest'
            && $body['instructions'] === 'Answer from the documents.'
            && $body['store'] === false
            && $body['stream'] === false
            && $body['inputs'] === [['role' => 'user', 'content' => 'Is Valkey mentioned?']]
            && in_array(['type' => 'document_library', 'library_ids' => ['lib-123']], $body['tools']);
    });
});

test('chunked message output content is concatenated', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse([
            ['type' => 'text', 'text' => 'Yes, '],
            ['type' => 'tool_reference', 'tool' => 'document_library', 'title' => 'roadmap.txt'],
            ['type' => 'text', 'text' => 'Valkey is mentioned.'],
        ])),
    ]);

    $response = agent(tools: [new FileSearch(['lib-123'])])
        ->prompt('Is Valkey mentioned?', provider: 'mistral');

    expect((string) $response)->toBe('Yes, Valkey is mentioned.');
});

test('single object message output content is extracted', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse([
            'type' => 'text', 'text' => 'Valkey is mentioned.',
        ])),
    ]);

    $response = agent(tools: [new FileSearch(['lib-123'])])
        ->prompt('Is Valkey mentioned?', provider: 'mistral');

    expect((string) $response)->toBe('Valkey is mentioned.');
});

test('function calls in conversations trigger the tool loop', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::sequence([
            Http::response([
                'object' => 'conversation.response',
                'conversation_id' => 'conv-123',
                'outputs' => [
                    [
                        'object' => 'entry',
                        'type' => 'function.call',
                        'tool_call_id' => 'call-1',
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
            Http::response(fakeMistralConversationResponse('The number is 72019.')),
        ]),
    ]);

    $response = agent(tools: [new FileSearch(['lib-123']), new FixedNumberGenerator])
        ->prompt('Generate a number', provider: 'mistral');

    expect((string) $response)->toBe('The number is 72019.');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUp = json_decode($recorded[1][0]->body(), true);

    $hasFunctionCall = collect($followUp['inputs'])->contains(fn ($input) => ($input['type'] ?? null) === 'function.call'
        && $input['name'] === 'FixedNumberGenerator');

    $hasFunctionResult = collect($followUp['inputs'])->contains(fn ($input) => ($input['type'] ?? null) === 'function.result'
        && $input['tool_call_id'] === 'call-1');

    expect($hasFunctionCall)->toBeTrue()
        ->and($hasFunctionResult)->toBeTrue();
});

test('streaming with file search emits synthetic text events', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse()),
    ]);

    $stream = agent(tools: [new FileSearch(['lib-123'])])
        ->stream('Is Valkey mentioned?', provider: 'mistral');

    $events = iterator_to_array($stream, false);

    expect($events[0])->toBeInstanceOf(StreamStart::class)
        ->and($events[1])->toBeInstanceOf(TextStart::class)
        ->and($events[2])->toBeInstanceOf(TextDelta::class)->delta->toBe('Valkey is mentioned.')
        ->and($events[3])->toBeInstanceOf(TextEnd::class)
        ->and($events[4])->toBeInstanceOf(StreamEnd::class);
});
