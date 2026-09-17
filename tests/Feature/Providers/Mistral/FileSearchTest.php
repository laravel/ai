<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\TopP;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\ToolChoiceAgent;
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

test('attachments with file search map to conversation content chunks', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse()),
    ]);

    agent(tools: [new FileSearch(['lib-123'])])
        ->prompt('Describe this', attachments: [new RemoteImage('https://example.com/image.png')], provider: 'mistral');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['inputs'] === [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Describe this'],
                ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png']],
            ],
        ]];
    });
});

test('provider document attachments with file search throw an exception', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse()),
    ]);

    expect(fn () => agent(tools: [new FileSearch(['lib-123'])])
        ->prompt('Describe this', attachments: [Document::fromId('file-123')], provider: 'mistral'))
        ->toThrow(RuntimeException::class, 'Mistral does not support stored provider document attachments when using file search.');

    Http::assertNothingSent();
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
            && $body['model'] === 'mistral-large-2512'
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

test('generation options map to conversation completion args', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse()),
    ]);

    $agent = new #[MaxTokens(4096), Temperature(0.7), TopP(0.8)] class extends AssistantAgent implements HasTools
    {
        public function tools(): iterable
        {
            return [new FileSearch(['lib-123'])];
        }
    };

    $agent->prompt('Is Valkey mentioned?', provider: 'mistral');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return $body['completion_args'] === ['temperature' => 0.7, 'top_p' => 0.8, 'max_tokens' => 4096]
            && ! array_key_exists('temperature', $body)
            && ! array_key_exists('max_tokens', $body);
    });
});

test('completion args are omitted when no generation options are set', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse()),
    ]);

    agent(tools: [new FileSearch(['lib-123'])])->prompt('Is Valkey mentioned?', provider: 'mistral');

    Http::assertSent(fn (Request $request) => ! array_key_exists('completion_args', json_decode($request->body(), true)));
});

test('tool choice maps to conversation completion args', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse()),
    ]);

    $agent = new class('required') extends ToolChoiceAgent
    {
        public function tools(): iterable
        {
            return [new FileSearch(['lib-123']), ...parent::tools()];
        }
    };

    $agent->prompt('Is Valkey mentioned?', provider: 'mistral');

    Http::assertSent(fn (Request $request) => data_get(json_decode($request->body(), true), 'completion_args.tool_choice') === 'required');
});

test('named tool choice with file search throws an exception', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse()),
    ]);

    $agent = new class(['tool' => 'custom_named_tool']) extends ToolChoiceAgent
    {
        public function tools(): iterable
        {
            return [new FileSearch(['lib-123']), ...parent::tools()];
        }
    };

    expect(fn () => $agent->prompt('Is Valkey mentioned?', provider: 'mistral'))
        ->toThrow(RuntimeException::class, 'Mistral does not support forcing a specific tool when using file search.');
});

test('structured output with file search sends a response format and decodes the result', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse('{"symbol":"Au"}')),
    ]);

    $agent = new class extends StructuredAgent implements HasTools
    {
        public function tools(): iterable
        {
            return [new FileSearch(['lib-123'])];
        }
    };

    $response = $agent->prompt('What is the symbol for gold?', provider: 'mistral');

    expect($response['symbol'])->toBe('Au');

    Http::assertSent(fn (Request $request) => data_get(json_decode($request->body(), true), 'completion_args.response_format.type') === 'json_schema');
});

test('conversation usage is parsed', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response(fakeMistralConversationResponse()),
    ]);

    $response = agent(tools: [new FileSearch(['lib-123'])])->prompt('Is Valkey mentioned?', provider: 'mistral');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5)
        ->and($response->meta->provider)->toBe('mistral')
        ->and($response->meta->model)->toBe('mistral-medium-latest');
});

test('conversation api errors are surfaced', function () {
    Http::fake([
        'api.mistral.ai/v1/conversations' => Http::response([
            'object' => 'error',
            'message' => 'Library not found.',
            'type' => 'invalid_request_error',
        ]),
    ]);

    agent(tools: [new FileSearch(['lib-404'])])->prompt('Is Valkey mentioned?', provider: 'mistral');
})->throws(AiException::class, 'Library not found.');
