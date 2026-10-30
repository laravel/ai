<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Tests\Fixtures\Agents\ToolUsingAgent;
use Tests\Fixtures\Tools\FileReturningTool;

use function Laravel\Ai\agent;

test('tool calls trigger follow up request', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = $recorded[1][0]->data();

    $hasAssistantWithToolUse = false;
    $hasToolResult = false;

    foreach ($followUpBody['messages'] as $message) {
        if ($message['role'] === 'assistant') {
            foreach ($message['content'] as $block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    $hasAssistantWithToolUse = true;
                }
            }
        }

        if ($message['role'] === 'user') {
            foreach ($message['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    $hasToolResult = true;
                }
            }
        }
    }

    expect($hasAssistantWithToolUse)->toBeTrue('Follow-up request should include assistant message with tool_use block')
        ->and($hasToolResult)->toBeTrue('Follow-up request should include user message with tool_result block');
});

test('tool calls run when the message ends with end_turn', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => 'Let me generate a number.'],
                    ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    expect(Http::recorded())->toHaveCount(2)
        ->and($response->text)->toBe('The number is 72019');
});

test('tool calls do not run when the message ends with max_tokens', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_tool_123',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => 'Let me generate a number.'],
                ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
            ],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'anthropic');

    expect(Http::recorded())->toHaveCount(1);
});

test('server_tool_use input is serialized as object on follow-up replay', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_tool_123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'server_tool_use', 'id' => 'srvtoolu_123', 'name' => 'advisor', 'input' => (object) []],
                    ['type' => 'tool_use', 'id' => 'toolu_123', 'name' => 'FixedNumberGenerator', 'input' => (object) []],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            $this->fakeTextResponse('done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'anthropic');

    $followUpBody = Http::recorded()[1][0]->body();

    expect($followUpBody)
        ->toContain('"type":"server_tool_use"')
        ->not->toContain('"input":[]');
});

test('pause_turn resumes when last block is text following a web_search_tool_result', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_pause',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_1',
                        'name' => 'web_search',
                        'input' => (object) ['query' => 'q'],
                    ],
                    [
                        'type' => 'web_search_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => [],
                    ],
                    ['type' => 'text', 'text' => 'Paused mid-summary.'],
                ],
                'stop_reason' => 'pause_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Ask',
        provider: 'anthropic',
    );

    expect(Http::recorded())->toHaveCount(2);
});

test('full compose: pause_turn + server_tool_use replay with input cast and block order preserved', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            Http::response([
                'id' => 'msg_compose',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_1',
                        'name' => 'advisor',
                        'input' => (object) [],
                    ],
                    [
                        'type' => 'advisor_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => ['type' => 'advisor_result', 'text' => 'Plan.'],
                    ],
                    ['type' => 'text', 'text' => 'Consulting again.'],
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_2',
                        'name' => 'advisor',
                        'input' => (object) [],
                    ],
                ],
                'stop_reason' => 'pause_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Compose test',
        provider: 'anthropic',
    );

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $payload = json_decode((string) $recorded[1][0]->body(), false, 512, JSON_THROW_ON_ERROR);
    $assistant = collect($payload->messages)->first(fn ($m): bool => $m->role === 'assistant');

    expect(array_column((array) $assistant->content, 'type'))->toBe([
        'server_tool_use',
        'advisor_tool_result',
        'text',
        'server_tool_use',
    ]);

    $serverBlocks = collect($assistant->content)->where('type', 'server_tool_use')->values();
    expect($serverBlocks)->toHaveCount(2)
        ->and($serverBlocks[0]->input)->toBeInstanceOf(stdClass::class)
        ->and($serverBlocks[1]->input)->toBeInstanceOf(stdClass::class);
});

test('max steps limits tool call depth', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
            $this->fakeUniqueToolCallResponse(),
            $this->fakeTextResponse('Done'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate numbers',
        provider: 'anthropic',
    );

    $recorded = Http::recorded();

    expect(count($recorded))->toBeLessThanOrEqual(3);
});

test('a file a tool returns is sent to the model as a block of its tool result', function (Image|Document $file, array $block): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeToolCallResponse('FileReturningTool'),
            $this->fakeTextResponse('It is a chart.'),
        ]),
    ]);

    agent(tools: [new FileReturningTool($file)])->prompt('What does the tool return?', provider: 'anthropic');

    expect(collect(Http::recorded()[1][0]->data()['messages'])->last()['content'])->toBe([[
        'type' => 'tool_result',
        'tool_use_id' => 'toolu_123',
        'content' => [['type' => 'text', 'text' => $file->name()], $block],
    ]]);
})->with([
    'image' => [
        fn () => Image::fromBase64(base64_encode('png-bytes'), 'image/png')->as('chart.png'),
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode('png-bytes')]],
    ],
    'stored pdf' => [
        function () {
            Storage::fake('docs')->put('invoice.pdf', '%PDF-1.4 invoice');

            return Document::fromStorage('invoice.pdf', 'docs');
        },
        ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 invoice')], 'title' => 'invoice.pdf'],
    ],
]);

test('a file a tool returns that anthropic cannot take is noted in its tool result', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeToolCallResponse('FileReturningTool'),
            $this->fakeTextResponse('It is a report.'),
        ]),
    ]);

    $file = Document::fromBase64(base64_encode("PK\x03\x04\x00report"), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')->as('report.docx');

    agent(tools: [new FileReturningTool($file)])->prompt('What does the tool return?', provider: 'anthropic');

    expect(collect(Http::recorded()[1][0]->data()['messages'])->last()['content'])->toBe([[
        'type' => 'tool_result',
        'tool_use_id' => 'toolu_123',
        'content' => [['type' => 'text', 'text' => "report.docx\n[The document report.docx was left out because it could not be sent to this provider.]"]],
    ]]);
});

test('only the files of a tool result that anthropic cannot take are noted', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('Done'),
    ]);

    agent(messages: [
        new UserMessage('Show me the files.'),
        new AssistantMessage('', collect([new ToolCall('toolu_123', 'FileReturningTool', [], 'toolu_123')])),
        new ToolResultMessage(collect([new ToolResult('toolu_123', 'FileReturningTool', [], 'files', 'toolu_123', attachments: [
            Image::fromBase64(base64_encode('png-bytes'), 'image/png')->as('chart.png'),
            Document::fromBase64(base64_encode("PK\x03\x04\x00report"), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')->as('report.docx'),
        ])])),
    ])->prompt('What are they?', provider: 'anthropic');

    expect(Http::recorded()[0][0]->data()['messages'][2]['content'][0]['content'])->toBe([
        ['type' => 'text', 'text' => "files\n[The document report.docx was left out because it could not be sent to this provider.]"],
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode('png-bytes')]],
    ]);
});
