<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ToolUsingAgent;
use Tests\Feature\Providers\Gemini\GeminiHelpers;

use function Laravel\Ai\agent;

uses(GeminiHelpers::class);

test('user message maps to gemini format', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'gemini',
    );

    Http::assertSent(function ($request) {
        $contents = $request->data()['contents'];
        $userMessage = $contents[0];

        return $userMessage['role'] === 'user'
            && $userMessage['parts'][0]['text'] === 'What is Laravel?';
    });
});

test('tool result follow up maps model and function response', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'gemini',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpContents = $recorded[1][0]->data()['contents'];

    $hasModelWithFunctionCall = false;
    $hasFunctionResponse = false;

    foreach ($followUpContents as $content) {
        if ($content['role'] === 'model') {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionCall'])) {
                    $hasModelWithFunctionCall = true;
                }
            }
        }

        if ($content['role'] === 'user') {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionResponse'])) {
                    $hasFunctionResponse = true;
                }
            }
        }
    }

    expect($hasModelWithFunctionCall)->toBeTrue('Follow-up should include model message with functionCall');
    expect($hasFunctionResponse)->toBeTrue('Follow-up should include user message with functionResponse');
});

test('base64 pdf document maps to inline data', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see a PDF'),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'gemini',
    );

    Http::assertSent(function ($request) {
        $parts = $request->data()['contents'][0]['parts'];

        foreach ($parts as $part) {
            if (isset($part['inlineData'])) {
                return $part['inlineData']['mimeType'] === 'application/pdf'
                    && $part['inlineData']['data'] === base64_encode('fake-pdf-content');
            }
        }

        return false;
    });
});

test('system instructions are not in contents array', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        foreach ($body['contents'] as $content) {
            if ($content['role'] === 'system') {
                return false;
            }
        }

        return isset($body['system_instruction']);
    });
});
