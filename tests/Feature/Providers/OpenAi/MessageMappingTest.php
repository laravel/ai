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

test('tool result follow up uses previous response id', function () {
    Http::fake([
        'api.openai.com/*' => Http::sequence([
            fakeOpenAiToolCallResponse(),
            fakeOpenAiResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'openai',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id')
        ->and($followUpBody['previous_response_id'])->toBe('resp_tool_123');

    $hasFunctionCallOutput = false;

    foreach ($followUpBody['input'] as $item) {
        if (($item['type'] ?? '') === 'function_call_output') {
            $hasFunctionCallOutput = true;
            expect($item['call_id'])->toBe('call_123')
                ->and($item['output'])->not->toBeEmpty();
        }
    }

    expect($hasFunctionCallOutput)->toBeTrue();
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
