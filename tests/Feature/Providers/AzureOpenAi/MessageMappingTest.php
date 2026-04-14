<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.azure' => [
        ...config('ai.providers.azure'),
        'key' => 'test-key',
        'url' => 'https://my-resource.openai.azure.com',
        'api_version' => '2024-10-21',
        'deployment' => 'gpt-4o',
    ]]);
});

test('user message maps to azure format', function () {
    Http::fake([
        'my-resource.openai.azure.com/*' => fakeAzureResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'azure',
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
        'my-resource.openai.azure.com/*' => Http::sequence([
            fakeAzureToolCallResponse(),
            fakeAzureResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'azure',
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
        'my-resource.openai.azure.com/*' => fakeAzureResponse('I see an image'),
    ]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'azure',
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
    Http::fake([
        'my-resource.openai.azure.com/*' => fakeAzureResponse(),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'azure',
    );
})->throws(InvalidArgumentException::class);

function fakeAzureToolCallResponse()
{
    return Http::response([
        'id' => 'chatcmpl-tool-'.uniqid(),
        'object' => 'chat.completion',
        'model' => 'gpt-4o',
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_'.uniqid(),
                    'type' => 'function',
                    'function' => [
                        'name' => 'FixedNumberGenerator',
                        'arguments' => '{}',
                    ],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ],
    ]);
}
