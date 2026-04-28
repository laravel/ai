<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\LocalImage;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

test('user message maps to anthropic format', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $messages = $request->data()['messages'];
        $userMessage = $messages[0];

        return $userMessage['role'] === 'user'
            && $userMessage['content'][0]['type'] === 'text'
            && $userMessage['content'][0]['text'] === 'What is Laravel?';
    });
});

test('tool result follow up maps assistant and tool result messages', function () {
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

test('local image attachment without explicit mime type detects mime from file', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('I see an image'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $content = $request->data()['messages'][0]['content'];
        $imageBlock = collect($content)->firstWhere('type', 'image');

        return $imageBlock !== null
            && $imageBlock['source']['type'] === 'base64'
            && $imageBlock['source']['media_type'] === 'image/png';
    });
});

test('base64 pdf document maps to document content block', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('I see a PDF'),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $content = $request->data()['messages'][0]['content'];
        $docBlock = $content[0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'base64'
            && $docBlock['source']['media_type'] === 'application/pdf'
            && $docBlock['source']['data'] === base64_encode('fake-pdf-content');
    });
});

test('base64 text document maps to text source block', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    $document = Files\Document::fromString('hello world', 'text/plain');

    agent('You are helpful.')->prompt(
        'Read this.',
        attachments: [$document],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $docBlock = $request->data()['messages'][0]['content'][0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'text'
            && $docBlock['source']['media_type'] === 'text/plain'
            && $docBlock['source']['data'] === 'hello world';
    });
});

test('stored text document maps to text source block', function () {
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

    Http::assertSent(function ($request) {
        $docBlock = $request->data()['messages'][0]['content'][0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'text'
            && $docBlock['source']['media_type'] === 'text/plain'
            && $docBlock['source']['data'] === 'stored text contents';
    });
});

test('local text document maps to text source block', function () {
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

        Http::assertSent(function ($request) {
            $docBlock = $request->data()['messages'][0]['content'][0];

            return $docBlock['type'] === 'document'
                && $docBlock['source']['type'] === 'text'
                && str_starts_with($docBlock['source']['media_type'], 'text/')
                && $docBlock['source']['data'] === 'local text contents';
        });
    } finally {
        @unlink($path);
    }
});

test('uploaded text file maps to text source block', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    $upload = UploadedFile::fake()->createWithContent('notes.txt', 'uploaded text contents');

    agent('You are helpful.')->prompt(
        'Read this.',
        attachments: [$upload],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $docBlock = $request->data()['messages'][0]['content'][0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'text'
            && $docBlock['source']['data'] === 'uploaded text contents';
    });
});

test('uploaded pdf file maps to document content block', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('I see a PDF'),
    ]);

    $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this file?',
        attachments: [$file],
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $content = $request->data()['messages'][0]['content'];
        $docBlock = $content[0];

        return $docBlock['type'] === 'document'
            && $docBlock['source']['type'] === 'base64'
            && $docBlock['source']['media_type'] === 'application/pdf';
    });
});

test('system instructions are not in messages array', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        foreach ($body['messages'] as $message) {
            if ($message['role'] === 'system') {
                return false;
            }
        }

        return isset($body['system']) && is_string($body['system']);
    });
});
