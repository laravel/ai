<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

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

    $modelFunctionCall = null;
    $hasFunctionResponse = false;

    foreach ($followUpContents as $content) {
        if ($content['role'] === 'model') {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['functionCall'])) {
                    $modelFunctionCall = $part['functionCall'];
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

    // Regression: laravel/ai#388 — an empty `args: []` or response-only `id`
    // field on a request `functionCall` causes Gemini to reject the call with
    // a 400. The continuation must omit both for argument-less tool calls.
    expect($modelFunctionCall)->not->toBeNull('Follow-up should include model message with functionCall')
        ->and($modelFunctionCall)->not->toHaveKey('args')
        ->and($modelFunctionCall)->not->toHaveKey('id')
        ->and($hasFunctionResponse)->toBeTrue('Follow-up should include user message with functionResponse');
});

test('prior assistant tool call with empty arguments omits args in conversation history', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('OK'),
    ]);

    $agent = new class implements Agent, Conversational
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function messages(): iterable
        {
            return [
                new Message(role: 'user', content: 'Generate a number'),
                new AssistantMessage('', new Collection([
                    new ToolCall('call_123', 'FixedNumberGenerator', [], 'call_123'),
                ])),
            ];
        }
    };

    $agent->prompt('And again', provider: 'gemini');

    Http::assertSent(function ($request) {
        $modelFunctionCall = collect($request->data()['contents'])
            ->where('role', 'model')
            ->flatMap(fn ($content) => $content['parts'] ?? [])
            ->firstWhere(fn ($part) => isset($part['functionCall']))['functionCall'] ?? null;

        // Regression: laravel/ai#388 — when args is empty, the JSON must not
        // include "args": [] (empty PHP array re-encoded as a list).
        return $modelFunctionCall !== null
            && ! array_key_exists('args', $modelFunctionCall);
    });
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

test('stored text document sends real mime type', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    Storage::fake('docs');
    Storage::disk('docs')->put('notes.txt', 'stored text contents');

    agent('You are helpful.')->prompt(
        'Read this.',
        attachments: [Files\Document::fromStorage('notes.txt', 'docs')],
        provider: 'gemini',
    );

    Http::assertSent(function ($request) {
        $parts = $request->data()['contents'][0]['parts'];

        foreach ($parts as $part) {
            if (isset($part['inlineData'])) {
                return $part['inlineData']['mimeType'] === 'text/plain'
                    && $part['inlineData']['data'] === base64_encode('stored text contents');
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
