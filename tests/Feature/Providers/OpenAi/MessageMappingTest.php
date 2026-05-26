<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.openai' => [
        ...config('ai.providers.openai'),
        'key' => 'test-key',
    ]]);
});

test('user message maps to openai format', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'openai',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $input = $body['input'];
        $userMessage = collect($input)->firstWhere('role', 'user');

        return $userMessage !== null
            && $userMessage['content'][0]['type'] === 'input_text'
            && $userMessage['content'][0]['text'] === 'What is Laravel?';
    });
});

test('tool result follow up inlines the full conversation without previous_response_id', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiToolCallResponse(),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    $followUpBody = json_decode(Http::recorded()[1][0]->body(), true);

    expect($followUpBody)->not->toHaveKey('previous_response_id')
        ->and($followUpBody['input'])->sequence(
            fn ($item) => $item->role->toBe('system'),
            fn ($item) => $item->toMatchArray([
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => 'Generate a number']],
            ]),
            fn ($item) => $item->toMatchArray(['type' => 'function_call', 'call_id' => 'call_123']),
            fn ($item) => $item->toMatchArray(['type' => 'function_call_output', 'call_id' => 'call_123'])
                ->output->not->toBeEmpty(),
        );
});

test('base64 pdf document maps to input file', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('I see a PDF'),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'openai',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->firstWhere('role', 'user');
        $content = $userMessage['content'];

        $fileBlock = collect($content)->firstWhere('type', 'input_file');

        return $fileBlock !== null
            && str_contains($fileBlock['file_data'], 'application/pdf')
            && str_contains($fileBlock['file_data'], base64_encode('fake-pdf-content'));
    });
});

test('nameless text document falls back to derived filename', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse(),
    ]);

    agent('You are helpful.')->prompt(
        'Read this.',
        attachments: [Files\Document::fromString('hello world', 'text/plain')],
        provider: 'openai',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->firstWhere('role', 'user');
        $fileBlock = collect($userMessage['content'])->firstWhere('type', 'input_file');

        return $fileBlock !== null
            && ($fileBlock['filename'] ?? null) === 'document.txt';
    });
});

test('uploaded pdf file maps to input file', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('I see a PDF'),
    ]);

    $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this file?',
        attachments: [$file],
        provider: 'openai',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->firstWhere('role', 'user');
        $content = $userMessage['content'];

        $fileBlock = collect($content)->firstWhere('type', 'input_file');

        return $fileBlock !== null
            && str_contains($fileBlock['file_data'], 'application/pdf');
    });
});

test('local image attachment without explicit mime type detects mime from file', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('I see an image'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')],
        provider: 'openai',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['input'])->firstWhere('role', 'user');
        $imageBlock = collect($userMessage['content'])->firstWhere('type', 'input_image');

        return $imageBlock !== null
            && str_starts_with($imageBlock['image_url'], 'data:image/png;base64,')
            && ! str_contains($imageBlock['image_url'], 'data:;base64,');
    });
});

test('empty tool arguments serialize as object string on assistant replay', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('hi'),
    ]);

    agent(
        instructions: 'Hi.',
        tools: [(new ToolUsingAgent(fixed: true))->tools()[0]],
        messages: [
            new UserMessage('list'),
            new AssistantMessage('Listing.', collect([
                new ToolCall(
                    id: 'call_empty',
                    name: 'FixedNumberGenerator',
                    arguments: [],
                    resultId: 'call_empty',
                ),
            ])),
            new ToolResultMessage(collect([
                new ToolResult(
                    id: 'call_empty',
                    name: 'FixedNumberGenerator',
                    arguments: [],
                    result: '42',
                    resultId: 'call_empty',
                ),
            ])),
            new UserMessage('thanks'),
        ],
    )->prompt('', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $fnCall = collect($body['input'] ?? [])
            ->firstWhere('type', 'function_call');

        return $fnCall && $fnCall['arguments'] === '{}';
    });
});

test('non-empty tool arguments preserve shape on assistant replay', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('hi'),
    ]);

    agent(
        instructions: 'Hi.',
        tools: [(new ToolUsingAgent(fixed: true))->tools()[0]],
        messages: [
            new UserMessage('search'),
            new AssistantMessage('Searching.', collect([
                new ToolCall(
                    id: 'call_args',
                    name: 'FixedNumberGenerator',
                    arguments: ['query' => 'test'],
                    resultId: 'call_args',
                ),
            ])),
            new ToolResultMessage(collect([
                new ToolResult(
                    id: 'call_args',
                    name: 'FixedNumberGenerator',
                    arguments: ['query' => 'test'],
                    result: '42',
                    resultId: 'call_args',
                ),
            ])),
            new UserMessage('thanks'),
        ],
    )->prompt('', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $fnCall = collect($body['input'] ?? [])
            ->firstWhere('type', 'function_call');

        return $fnCall && json_decode($fnCall['arguments'], true) === ['query' => 'test'];
    });
});

test('reasoning blocks are interleaved with associated tool calls on assistant replay', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse('hi'),
    ]);

    agent(
        instructions: 'Hi.',
        tools: [(new ToolUsingAgent(fixed: true))->tools()[0]],
        messages: [
            new UserMessage('search'),
            new AssistantMessage('Searching.', collect([
                new ToolCall(
                    id: 'call_1',
                    name: 'FixedNumberGenerator',
                    arguments: ['q' => 'foo'],
                    resultId: 'call_1',
                    reasoningId: 'rs_1',
                    reasoningSummary: [],
                ),
                new ToolCall(
                    id: 'call_2',
                    name: 'FixedNumberGenerator',
                    arguments: ['q' => 'bar'],
                    resultId: 'call_2',
                    reasoningId: 'rs_2',
                    reasoningSummary: [],
                ),
                new ToolCall(
                    id: 'call_3',
                    name: 'FixedNumberGenerator',
                    arguments: ['q' => 'baz'],
                    resultId: 'call_3',
                ),
            ])),
            new ToolResultMessage(collect([
                new ToolResult(
                    id: 'call_1',
                    name: 'FixedNumberGenerator',
                    arguments: ['q' => 'foo'],
                    result: '42',
                    resultId: 'call_1',
                ),
            ])),
            new UserMessage('thanks'),
        ],
    )->prompt('', provider: 'openai');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $input = $body['input'];

        $rs1Index = collect($input)->search(fn ($i) => ($i['type'] ?? '') === 'reasoning' && ($i['id'] ?? '') === 'rs_1');
        $call1Index = collect($input)->search(fn ($i) => ($i['id'] ?? '') === 'call_1');
        $rs2Index = collect($input)->search(fn ($i) => ($i['type'] ?? '') === 'reasoning' && ($i['id'] ?? '') === 'rs_2');
        $call2Index = collect($input)->search(fn ($i) => ($i['id'] ?? '') === 'call_2');
        $call3Index = collect($input)->search(fn ($i) => ($i['id'] ?? '') === 'call_3');

        return $rs1Index !== false
            && $call1Index !== false
            && $rs1Index + 1 === $call1Index
            && $rs2Index !== false
            && $call2Index !== false
            && $rs2Index + 1 === $call2Index
            && $call3Index !== false;
    });
});

test('system instructions are in input array as system role', function () {
    Http::fake([
        'api.openai.com/*' => fakeOpenAiResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'openai',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $systemMsg = collect($body['input'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});

test('reasoning model requests auto-include reasoning.encrypted_content', function () {
    Http::fake(['api.openai.com/*' => fakeOpenAiResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: 'openai');

    Http::assertSent(fn (Request $request) => expect(json_decode($request->body(), true))
        ->include->toContain('reasoning.encrypted_content')
    );
});

test('non-reasoning model requests omit reasoning.encrypted_content include', function (string $model) {
    Http::fake(['api.openai.com/*' => fakeOpenAiResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: 'openai', model: $model);

    Http::assertSent(fn (Request $request) => expect(json_decode($request->body(), true)['include'] ?? [])
        ->not->toContain('reasoning.encrypted_content')
    );
})->with([
    'gpt-4.1',
    'gpt-4o',
    'gpt-5-chat-latest',
]);

test('store is not set so OpenAI defaults to its server-side behavior', function () {
    Http::fake(['api.openai.com/*' => fakeOpenAiResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: 'openai');

    Http::assertSent(fn (Request $request) => expect(json_decode($request->body(), true))
        ->not->toHaveKey('store')
    );
});

test('encrypted reasoning blobs round-trip through tool follow-up', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            Http::response([
                'id' => 'resp_rs',
                'status' => 'completed',
                'model' => 'gpt-5.4',
                'output' => [
                    ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'],
                    [
                        'type' => 'function_call',
                        'id' => 'fc_1',
                        'call_id' => 'call_1',
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                        'status' => 'completed',
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            fakeOpenAiResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'openai');

    $followUpBody = json_decode(Http::recorded()[1][0]->body(), true);
    $reasoningBlock = collect($followUpBody['input'])->firstWhere('type', 'reasoning');

    expect($reasoningBlock)->toMatchArray([
        'type' => 'reasoning',
        'id' => 'rs_1',
        'encrypted_content' => 'enc-blob-1',
    ]);
});
