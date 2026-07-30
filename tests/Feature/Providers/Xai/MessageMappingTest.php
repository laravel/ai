<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.xai' => [
        ...config('ai.providers.xai'),
        'key' => 'test-key',
    ]]);
});

test('user message maps to responses api format', function (): void {
    Http::fake(['*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('What is Laravel?', provider: 'xai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['input'])->firstWhere('role', 'user');

        return $userMsg !== null
            && collect($userMsg['content'])->contains(
                fn ($c): bool => ($c['type'] ?? '') === 'input_text' && $c['text'] === 'What is Laravel?'
            );
    });
});

test('tool call follow up uses previous response id', function (): void {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'xai');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

    expect($followUpBody)->toHaveKey('previous_response_id')
        ->and($followUpBody['previous_response_id'])->not->toBeEmpty();

    $hasToolOutput = collect($followUpBody['input'])->contains(
        fn ($item): bool => ($item['type'] ?? '') === 'function_call_output'
    );

    expect($hasToolOutput)->toBeTrue('Follow-up should include function_call_output');
});

test('remote image attachment maps to input image', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    $image = new RemoteImage('https://example.com/image.png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'xai',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['input'])->firstWhere('role', 'user');

        $imageBlock = collect($userMsg['content'])->firstWhere('type', 'input_image');

        return $imageBlock !== null
            && $imageBlock['image_url'] === 'https://example.com/image.png';
    });
});

test('base64 image attachment maps to data uri', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'xai',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['input'])->firstWhere('role', 'user');

        $imageBlock = collect($userMsg['content'])->firstWhere('type', 'input_image');

        return $imageBlock !== null
            && str_starts_with((string) $imageBlock['image_url'], 'data:image/png;base64,');
    });
});

test('local image attachment without explicit mime type detects mime from file', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')],
        provider: 'xai',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['input'])->firstWhere('role', 'user');
        $imageBlock = collect($userMsg['content'])->firstWhere('type', 'input_image');

        return $imageBlock !== null
            && str_starts_with((string) $imageBlock['image_url'], 'data:image/png;base64,')
            && ! str_contains((string) $imageBlock['image_url'], 'data:;base64,');
    });
});

test('remote document maps to input file', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

    $document = new RemoteDocument('https://example.com/report.pdf');

    agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [$document],
        provider: 'xai',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['input'])->firstWhere('role', 'user');

        $fileBlock = collect($userMsg['content'])->firstWhere('type', 'input_file');

        return $fileBlock !== null
            && $fileBlock['file_url'] === 'https://example.com/report.pdf';
    });
});

test('reasoning blocks are interleaved with associated tool calls on assistant replay', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('hi')]);

    agent(
        instructions: 'Hi.',
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
        tools: [(new ToolUsingAgent(fixed: true))->tools(Lab::xAI)[0]],
    )->prompt('', provider: 'xai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $input = $body['input'];

        $rs1Index = collect($input)->search(fn ($i): bool => ($i['type'] ?? '') === 'reasoning' && ($i['id'] ?? '') === 'rs_1');
        $call1Index = collect($input)->search(fn ($i): bool => ($i['id'] ?? '') === 'call_1');
        $rs2Index = collect($input)->search(fn ($i): bool => ($i['type'] ?? '') === 'reasoning' && ($i['id'] ?? '') === 'rs_2');
        $call2Index = collect($input)->search(fn ($i): bool => ($i['id'] ?? '') === 'call_2');
        $call3Index = collect($input)->search(fn ($i): bool => ($i['id'] ?? '') === 'call_3');

        return $rs1Index !== false
            && $call1Index !== false
            && $rs1Index + 1 === $call1Index
            && $rs2Index !== false
            && $call2Index !== false
            && $rs2Index + 1 === $call2Index
            && $call3Index !== false;
    });
});

test('system instructions are in input array', function (): void {
    Http::fake(['*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: 'xai');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        $systemMsg = collect($body['input'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains((string) $systemMsg['content'], 'helpful assistant');
    });
});
