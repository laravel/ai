<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Tests\Fixtures\Agents\AssistantAgent;

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
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('The number is 72019'),
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
            // Use non-sequential keys to ensure parts serialize as a JSON array
            $toolResults = collect([
                'custom_key' => new ToolResult('call_123', 'FixedNumberGenerator', [], 123),
            ]);

            return [
                new Message(role: 'user', content: 'Generate a number'),
                new AssistantMessage('', collect([
                    new ToolCall('call_123', 'FixedNumberGenerator', [], 'call_123'),
                ])),
                new ToolResultMessage($toolResults),
            ];
        }
    };

    $agent->prompt('Follow up', provider: 'gemini');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(1);

    $contents = $recorded[0][0]->data()['contents'];

    $modelFunctionCall = null;
    $userMessageParts = null;

    foreach ($contents as $content) {
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
                    $userMessageParts = $content['parts'];
                }
            }
        }
    }

    expect($modelFunctionCall)->not->toBeNull('Follow-up should include model message with functionCall')
        ->and($modelFunctionCall)->not->toHaveKey('args')
        ->and($modelFunctionCall)->not->toHaveKey('id')
        ->and($userMessageParts)->not->toBeNull('Follow-up should include user message with functionResponse')
        ->and(array_is_list($userMessageParts))->toBeTrue('Tool result parts must be a sequential array');
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

        return $modelFunctionCall !== null
            && ! array_key_exists('args', $modelFunctionCall);
    });
});

test('local image attachment without explicit mime type detects mime from file', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse('I see an image'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')],
        provider: 'gemini',
    );

    Http::assertSent(function ($request) {
        $parts = $request->data()['contents'][0]['parts'];

        foreach ($parts as $part) {
            if (isset($part['inlineData'])) {
                return $part['inlineData']['mimeType'] === 'image/png';
            }
        }

        return false;
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
