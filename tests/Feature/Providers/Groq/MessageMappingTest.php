<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\LocalImage;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.groq' => [
        ...config('ai.providers.groq'),
        'key' => 'test-key',
    ]]);
});

test('user message maps to groq format', function () {
    Http::fake([
        'api.groq.com/*' => fakeGroqResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'groq',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $messages = $body['messages'];
        $userMessage = collect($messages)->firstWhere('role', 'user');

        return $userMessage !== null
            && $userMessage['content'] === 'What is Laravel?';
    });
});

test('tool result follow up maps assistant and tool result messages', function () {
    Http::fake([
        'api.groq.com/*' => Http::sequence([
            fakeGroqToolCallResponse(),
            fakeGroqResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'groq',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);
    $followUpMessages = $followUpBody['messages'];

    $hasAssistantWithToolCalls = false;
    $hasToolResult = false;

    foreach ($followUpMessages as $msg) {
        if ($msg['role'] === 'assistant' && isset($msg['tool_calls'])) {
            $hasAssistantWithToolCalls = true;
        }

        if ($msg['role'] === 'tool') {
            $hasToolResult = true;
        }
    }

    expect($hasAssistantWithToolCalls)->toBeTrue()
        ->and($hasToolResult)->toBeTrue();

    $assistantMsg = collect($followUpMessages)->last(fn ($m) => $m['role'] === 'assistant' && isset($m['tool_calls']));
    $toolMsg = collect($followUpMessages)->last(fn ($m) => $m['role'] === 'tool');

    expect($assistantMsg['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator')
        ->and($toolMsg['tool_call_id'])->toBe($assistantMsg['tool_calls'][0]['id'])
        ->and($toolMsg['content'])->not->toBeEmpty();
});

test('image attachment maps to image url content block', function () {
    Http::fake([
        'api.groq.com/*' => fakeGroqResponse('I see an image'),
    ]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'groq',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->firstWhere('role', 'user');
        $content = $userMessage['content'];

        $imageBlock = collect($content)->firstWhere('type', 'image_url');

        return $imageBlock !== null
            && str_contains($imageBlock['image_url']['url'], 'image/png')
            && str_contains($imageBlock['image_url']['url'], base64_encode('fake-image-data'));
    });
});

test('local image attachment without explicit mime type detects mime from file', function () {
    Http::fake([
        'api.groq.com/*' => fakeGroqResponse('I see an image'),
    ]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')],
        provider: 'groq',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->firstWhere('role', 'user');
        $imageBlock = collect($userMessage['content'])->firstWhere('type', 'image_url');

        return $imageBlock !== null
            && str_starts_with($imageBlock['image_url']['url'], 'data:image/png;base64,')
            && ! str_contains($imageBlock['image_url']['url'], 'data:;base64,');
    });
});

test('document attachments throw exception', function () {
    Http::fake([
        'api.groq.com/*' => fakeGroqResponse(),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'groq',
    );
})->throws(InvalidArgumentException::class);
