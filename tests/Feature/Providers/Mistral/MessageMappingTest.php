<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('user message maps to chat format', function () {
    Http::fake(['*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('What is Laravel?', provider: 'mistral');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['messages'])->firstWhere('role', 'user');

        return $userMsg['content'] === 'What is Laravel?';
    });
});

test('assistant message with tool calls maps correctly', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'mistral');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);

    $assistantMsg = collect($followUpBody['messages'])->firstWhere('role', 'assistant');
    $toolMsg = collect($followUpBody['messages'])->firstWhere('role', 'tool');

    expect($assistantMsg)->not->toBeNull();
    expect($toolMsg)->not->toBeNull();
    expect($assistantMsg['tool_calls'])->not->toBeEmpty();
    expect($assistantMsg['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator');
    expect($toolMsg['tool_call_id'])->toBe($assistantMsg['tool_calls'][0]['id']);
});

test('image attachment maps to image url', function () {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    $image = new RemoteImage('https://example.com/image.png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        if (! is_array($content)) {
            return false;
        }

        $imageBlock = collect($content)->firstWhere('type', 'image_url');

        return $imageBlock !== null
            && $imageBlock['image_url']['url'] === 'https://example.com/image.png';
    });
});

test('base64 image attachment maps to data uri', function () {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        if (! is_array($content)) {
            return false;
        }

        $imageBlock = collect($content)->firstWhere('type', 'image_url');

        return $imageBlock !== null
            && str_starts_with($imageBlock['image_url']['url'], 'data:image/png;base64,');
    });
});

test('remote document maps to document url', function () {
    Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

    $document = new RemoteDocument('https://example.com/report.pdf');

    agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [$document],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        if (! is_array($content)) {
            return false;
        }

        $docBlock = collect($content)->firstWhere('type', 'document_url');

        return $docBlock !== null
            && $docBlock['document_url'] === 'https://example.com/report.pdf';
    });
});

test('system instructions are in messages array', function () {
    Http::fake(['*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: 'mistral');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains($systemMsg['content'], 'helpful assistant');
    });
});
