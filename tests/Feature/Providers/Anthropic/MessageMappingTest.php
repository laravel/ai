<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

test('user message maps to anthropic format', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $messages = $request->data()['messages'];
        $userMessage = $messages[0];

        return $userMessage['role'] === 'user'
            && $userMessage['content'][0]['type'] === 'text'
            && $userMessage['content'][0]['text'] === 'What is Laravel?';
    });
});

test('tool result follow up maps assistant and tool result messages', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'anthropic',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpMessages = $recorded[1][0]->data()['messages'];

    $assistantMsg = null;
    $toolResultMsg = null;

    foreach ($followUpMessages as $msg) {
        if ($msg['role'] === 'assistant') {
            foreach ($msg['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'tool_use') {
                    $assistantMsg = $msg;
                }
            }
        }

        if ($msg['role'] === 'user') {
            foreach ($msg['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    $toolResultMsg = $msg;
                }
            }
        }
    }

    expect($assistantMsg)->not->toBeNull('Follow-up should include assistant message')
        ->and($toolResultMsg)->not->toBeNull('Follow-up should include tool result message');

    $toolUseBlock = collect($assistantMsg['content'])->firstWhere('type', 'tool_use');
    expect($toolUseBlock['name'])->toBe('FixedNumberGenerator')
        ->and($toolUseBlock)->toHaveKey('input');

    $toolResultBlock = collect($toolResultMsg['content'])->firstWhere('type', 'tool_result');
    expect($toolResultBlock['tool_use_id'])->toBe($toolUseBlock['id'])
        ->and($toolResultBlock['content'])->not->toBeEmpty();
});

test('local image attachment without explicit mime type detects mime from file', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('I see an image'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $content = $request->data()['messages'][0]['content'];
        $imageBlock = collect($content)->firstWhere('type', 'image');

        return $imageBlock !== null
            && $imageBlock['source']['type'] === 'base64'
            && $imageBlock['source']['media_type'] === 'image/png';
    });
});

test('base64 pdf document maps to document content block', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('I see a PDF'),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $content = $request->data()['messages'][0]['content'];
        $docBlock = $content[0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'base64'
            && $docBlock['source']['media_type'] === 'application/pdf'
            && $docBlock['source']['data'] === base64_encode('fake-pdf-content');
    });
});

test('base64 text document maps to text source block', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    $document = Files\Document::fromString('hello world', 'text/plain');

    agent('You are helpful.')->prompt(
        'Read this.',
        attachments: [$document],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $docBlock = $request->data()['messages'][0]['content'][0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'text'
            && $docBlock['source']['media_type'] === 'text/plain'
            && $docBlock['source']['data'] === 'hello world';
    });
});

test('stored text document maps to text source block', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    Storage::fake('docs');
    Storage::disk('docs')->put('notes.txt', 'stored text contents');

    agent('You are helpful.')->prompt(
        'Analyze the attached record.',
        attachments: [Files\Document::fromStorage('notes.txt', 'docs')],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $docBlock = $request->data()['messages'][0]['content'][0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'text'
            && $docBlock['source']['media_type'] === 'text/plain'
            && $docBlock['source']['data'] === 'stored text contents';
    });
});

test('base64 text document that is not plain text is sent as plain text', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    // A text source only accepts text/plain, so a csv sent under its own media
    // type is rejected before the model reads a byte.
    $document = Files\Document::fromString("email,state\na@b.it,ongoing\n", 'text/csv');

    agent('You are helpful.')->prompt(
        'Read this.',
        attachments: [$document],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $docBlock = $request->data()['messages'][0]['content'][0];

        return $docBlock['source']['type'] === 'text'
            && $docBlock['source']['media_type'] === 'text/plain'
            && $docBlock['source']['data'] === "email,state\na@b.it,ongoing\n";
    });
});

test('stored text document that is not plain text is sent as plain text', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    Storage::fake('docs');
    Storage::disk('docs')->put('leads.csv', "email,state\na@b.it,ongoing\n");

    agent('You are helpful.')->prompt(
        'Analyze the attached record.',
        attachments: [Files\Document::fromStorage('leads.csv', 'docs')->withMimeType('text/csv')],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $docBlock = $request->data()['messages'][0]['content'][0];

        return $docBlock['source']['type'] === 'text'
            && $docBlock['source']['media_type'] === 'text/plain'
            && $docBlock['source']['data'] === "email,state\na@b.it,ongoing\n";
    });
});

test('local text document maps to text source block', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    $path = tempnam(sys_get_temp_dir(), 'ai-').'.txt';
    file_put_contents($path, 'local text contents');

    try {
        agent('You are helpful.')->prompt(
            'Read this.',
            attachments: [Files\Document::fromPath($path)],
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['type'] === 'document'
                && $docBlock['source']['type'] === 'text'
                && str_starts_with((string) $docBlock['source']['media_type'], 'text/')
                && $docBlock['source']['data'] === 'local text contents';
        });
    } finally {
        @unlink($path);
    }
});

test('uploaded text file maps to text source block', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    $upload = UploadedFile::fake()->createWithContent('notes.txt', 'uploaded text contents');

    agent('You are helpful.')->prompt(
        'Read this.',
        attachments: [$upload],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $docBlock = $request->data()['messages'][0]['content'][0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'text'
            && $docBlock['source']['data'] === 'uploaded text contents';
    });
});

test('uploaded pdf file maps to document content block', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('I see a PDF'),
    ]);

    $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this file?',
        attachments: [$file],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $content = $request->data()['messages'][0]['content'];
        $docBlock = $content[0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'base64'
            && $docBlock['source']['media_type'] === 'application/pdf';
    });
});

test('empty tool arguments serialize as object on assistant replay', function (): void {
    $assistant = new AssistantMessage('Listing.', collect([
        new ToolCall(
            id: 'toolu_empty',
            name: 'ListTool',
            arguments: [],
        ),
    ]));

    $gateway = app(AnthropicGateway::class);
    $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

    $mapped = $method->invoke($gateway, [$assistant]);
    $toolUse = collect($mapped[0]['content'])->firstWhere('type', 'tool_use');

    expect($toolUse['input'])->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($toolUse['input']))->toBeEmpty();
});

test('non-empty tool arguments preserve shape on assistant replay', function (): void {
    $assistant = new AssistantMessage('Searching.', collect([
        new ToolCall(
            id: 'toolu_args',
            name: 'SearchTool',
            arguments: ['query' => 'test'],
        ),
    ]));

    $gateway = app(AnthropicGateway::class);
    $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

    $mapped = $method->invoke($gateway, [$assistant]);
    $toolUse = collect($mapped[0]['content'])->firstWhere('type', 'tool_use');

    expect($toolUse['input'])->toBe(['query' => 'test']);
});

test('assistant message with provider content blocks is replayed verbatim preserving order', function (): void {
    $contentBlocks = [
        ['type' => 'text', 'text' => 'Let me consult the advisor.'],
        [
            'type' => 'server_tool_use',
            'id' => 'srvtoolu_abc',
            'name' => 'advisor',
            'input' => [],
        ],
        [
            'type' => 'advisor_tool_result',
            'tool_use_id' => 'srvtoolu_abc',
            'content' => [
                'type' => 'advisor_result',
                'text' => 'Use a channel-based coordination pattern.',
            ],
        ],
        [
            'type' => 'tool_use',
            'id' => 'toolu_xyz',
            'name' => 'write_file',
            'input' => ['path' => 'worker.go'],
        ],
        ['type' => 'text', 'text' => "Here's the implementation."],
    ];

    $assistant = new AssistantMessage("Here's the implementation.", null, $contentBlocks);

    $gateway = app(AnthropicGateway::class);
    $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

    $mapped = $method->invoke($gateway, [$assistant]);

    expect($mapped)->toHaveCount(1)
        ->and($mapped[0]['role'])->toBe('assistant')
        ->and(array_column($mapped[0]['content'], 'type'))->toBe([
            'text',
            'server_tool_use',
            'advisor_tool_result',
            'tool_use',
            'text',
        ]);

    $serverToolUse = collect($mapped[0]['content'])->firstWhere('type', 'server_tool_use');
    expect($serverToolUse['input'])->toBeInstanceOf(stdClass::class);
});

test('parsed response populates provider content blocks on the assistant message', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => 'Consulted the advisor.'],
                [
                    'type' => 'server_tool_use',
                    'id' => 'srvtoolu_1',
                    'name' => 'advisor',
                    'input' => (object) [],
                ],
                [
                    'type' => 'advisor_tool_result',
                    'tool_use_id' => 'srvtoolu_1',
                    'content' => ['type' => 'advisor_result', 'text' => 'Proceed.'],
                ],
                ['type' => 'text', 'text' => 'Done.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new AssistantAgent)->prompt('hi', provider: 'anthropic');

    $assistant = $response->messages->whereInstanceOf(AssistantMessage::class)->first();
    $blocks = $assistant->providerContentBlocks;

    expect($assistant)->not->toBeNull()
        ->and($blocks)->toHaveCount(4)
        ->and($blocks[0])->toBe(['type' => 'text', 'text' => 'Consulted the advisor.'])
        ->and($blocks[1])->toMatchArray([
            'type' => 'server_tool_use',
            'id' => 'srvtoolu_1',
            'name' => 'advisor',
        ])
        ->and($blocks[2])->toBe([
            'type' => 'advisor_tool_result',
            'tool_use_id' => 'srvtoolu_1',
            'content' => ['type' => 'advisor_result', 'text' => 'Proceed.'],
        ])
        ->and($blocks[3])->toBe(['type' => 'text', 'text' => 'Done.']);
});

test('assistant message produced by parser round-trips through mapping with server blocks intact', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => 'Searching.'],
                [
                    'type' => 'server_tool_use',
                    'id' => 'srvtoolu_1',
                    'name' => 'web_search',
                    'input' => (object) ['query' => 'laravel ai'],
                ],
                [
                    'type' => 'web_search_tool_result',
                    'tool_use_id' => 'srvtoolu_1',
                    'content' => [['title' => 'Laravel', 'url' => 'https://laravel.com']],
                ],
                ['type' => 'text', 'text' => 'Found it.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = (new AssistantAgent)->prompt('search laravel', provider: 'anthropic');
    $assistant = $response->messages->whereInstanceOf(AssistantMessage::class)->first();

    $gateway = app(AnthropicGateway::class);
    $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

    $mapped = $method->invoke($gateway, [$assistant]);
    $content = $mapped[0]['content'];

    expect($content)->toHaveCount(4)
        ->and($content[0])->toBe(['type' => 'text', 'text' => 'Searching.'])
        ->and($content[1])->toMatchArray([
            'type' => 'server_tool_use',
            'id' => 'srvtoolu_1',
            'name' => 'web_search',
        ])
        ->and($content[1]['input'])->toBeInstanceOf(stdClass::class)
        ->and((array) $content[1]['input'])->toBe(['query' => 'laravel ai'])
        ->and($content[2])->toBe([
            'type' => 'web_search_tool_result',
            'tool_use_id' => 'srvtoolu_1',
            'content' => [['title' => 'Laravel', 'url' => 'https://laravel.com']],
        ])
        ->and($content[3])->toBe(['type' => 'text', 'text' => 'Found it.']);
});

test('assistant message without provider content blocks falls back to text plus tool calls rebuild', function (): void {
    $assistant = new AssistantMessage('Hello');

    $gateway = app(AnthropicGateway::class);
    $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

    $mapped = $method->invoke($gateway, [$assistant]);

    expect($mapped[0]['role'])->toBe('assistant')
        ->and($mapped[0]['content'])->toBe([
            ['type' => 'text', 'text' => 'Hello'],
        ]);
});

test('thinking and redacted_thinking blocks are preserved on replay', function (): void {
    $contentBlocks = [
        ['type' => 'thinking', 'thinking' => 'Considering options.', 'signature' => 'sig_1'],
        ['type' => 'redacted_thinking', 'data' => 'opaque'],
        ['type' => 'text', 'text' => 'Answer.'],
    ];

    $assistant = new AssistantMessage('Answer.', null, $contentBlocks);

    $gateway = app(AnthropicGateway::class);
    $method = (new ReflectionClass($gateway))->getMethod('mapMessages');

    $mapped = $method->invoke($gateway, [$assistant]);

    expect($mapped[0]['content'])->toBe($contentBlocks);
});

test('system instructions are not in messages array', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        foreach ($body['messages'] as $message) {
            if ($message['role'] === 'system') {
                return false;
            }
        }

        return isset($body['system']) && is_string($body['system']);
    });
});
