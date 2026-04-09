<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ToolUsingAgent;

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

    expect($assistantMsg)->not->toBeNull('Follow-up should include assistant message');
    expect($toolResultMsg)->not->toBeNull('Follow-up should include tool result message');

    $toolUseBlock = collect($assistantMsg['content'])->firstWhere('type', 'tool_use');
    expect($toolUseBlock['name'])->toBe('FixedNumberGenerator');
    expect($toolUseBlock)->toHaveKey('input');

    $toolResultBlock = collect($toolResultMsg['content'])->firstWhere('type', 'tool_result');
    expect($toolResultBlock['tool_use_id'])->toBe($toolUseBlock['id']);
    expect($toolResultBlock['content'])->not->toBeEmpty();
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
