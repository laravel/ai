<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

test('user message content is coerced to string', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'workersai',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->firstWhere('role', 'user');

        return $userMessage !== null
            && is_string($userMessage['content'])
            && $userMessage['content'] === 'What is Laravel?';
    });
});

test('tool result follow up maps assistant and tool result messages', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(fakeWorkersAiToolCallResponse()),
            Http::response(workersAiTextResponse('The number is 72019')),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'workersai',
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
});

test('image attachment maps to image url content block', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse('I see an image'))]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'workersai',
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

test('document attachments throw exception', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(workersAiTextResponse())]);

    $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'workersai',
    );
})->throws(InvalidArgumentException::class);

