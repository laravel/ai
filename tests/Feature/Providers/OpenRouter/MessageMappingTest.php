<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\LocalImage;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.openrouter' => [
        ...config('ai.providers.openrouter'),
        'key' => 'test-key',
    ]]);
});

test('user message maps to chat completions format', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    agent()->prompt('Hello there', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['messages'])->firstWhere('role', 'user');

        return $userMsg !== null
            && $userMsg['content'] === 'Hello there';
    });
});

test('system instructions are sent as system role message', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('Hello')]);

    (new AssistantAgent)->prompt('Hello', provider: 'openrouter');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['messages'][0]['role'] === 'system'
            && str_contains((string) $body['messages'][0]['content'], 'helpful assistant');
    });
});

test('tool result follow up maps assistant and tool result messages', function (): void {
    Http::fake([
        '*' => Http::sequence([
            fakeOpenRouterToolCallResponse(),
            fakeOpenRouterResponse('The number is 72019'),
        ]),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt('Give me a number', provider: 'openrouter');

    $requests = Http::recorded(fn (Request $r): true => true);
    $followUpBody = json_decode((string) $requests[1][0]->body(), true);
    $messages = $followUpBody['messages'];

    $assistantMsg = collect($messages)->firstWhere('role', 'assistant');
    expect($assistantMsg)->not->toBeNull()
        ->and($assistantMsg)->toHaveKey('tool_calls')
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator');

    $toolMsg = collect($messages)->firstWhere('role', 'tool');
    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg['tool_call_id'])->toBe('call_123');
});

test('local image attachment without explicit mime type detects mime from file', function (): void {
    Http::fake(['*' => fakeOpenRouterResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')],
        provider: 'openrouter',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['messages'])->firstWhere('role', 'user');
        $imageBlock = collect($userMsg['content'])->firstWhere('type', 'image_url');

        return $imageBlock !== null
            && str_starts_with((string) $imageBlock['image_url']['url'], 'data:image/png;base64,')
            && ! str_contains((string) $imageBlock['image_url']['url'], 'data:;base64,');
    });
});
