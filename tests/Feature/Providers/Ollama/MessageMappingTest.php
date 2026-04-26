<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => '',
    ]]);
});

test('user message maps to ollama format', function () {
    Http::fake([
        '*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'What is Laravel?',
        provider: 'ollama',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $messages = $body['messages'];
        $userMessage = collect($messages)->firstWhere('role', 'user');

        return $userMessage !== null
            && $userMessage['content'] === 'What is Laravel?';
    });
});

test('tool result follow up uses tool_name not tool_call_id', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'ollama',
    );

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode($recorded[1][0]->body(), true);
    $followUpMessages = $followUpBody['messages'];

    $toolMsg = collect($followUpMessages)->first(fn ($m) => $m['role'] === 'tool');

    expect($toolMsg)->not->toBeNull()
        ->and($toolMsg)->toHaveKey('tool_name')
        ->and($toolMsg)->not->toHaveKey('tool_call_id')
        ->and($toolMsg['tool_name'])->toBe('FixedNumberGenerator');
});

test('assistant tool call message uses function format without type', function () {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'ollama',
    );

    $recorded = Http::recorded();
    $followUpBody = json_decode($recorded[1][0]->body(), true);
    $followUpMessages = $followUpBody['messages'];

    $assistantMsg = collect($followUpMessages)->first(
        fn ($m) => $m['role'] === 'assistant' && isset($m['tool_calls'])
    );

    expect($assistantMsg)->not->toBeNull();

    $toolCall = $assistantMsg['tool_calls'][0];

    expect($toolCall)->toHaveKey('function')
        ->and($toolCall)->not->toHaveKey('type')
        ->and($toolCall['function']['name'])->toBe('FixedNumberGenerator');
});

test('image attachment maps to images array with base64', function () {
    Http::fake([
        '*' => $this->fakeTextResponse('I see an image'),
    ]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'ollama',
    );

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $userMessage = collect($body['messages'])->firstWhere('role', 'user');

        return isset($userMessage['images'])
            && is_array($userMessage['images'])
            && $userMessage['images'][0] === base64_encode('fake-image-data');
    });
});

test('document attachments throw exception', function () {
    Http::fake([
        '*' => $this->fakeTextResponse(),
    ]);

    $pdf = new Base64Document(base64_encode('fake-pdf'), 'application/pdf');

    agent('You are helpful.')->prompt(
        'What is in this PDF?',
        attachments: [$pdf],
        provider: 'ollama',
    );
})->throws(InvalidArgumentException::class);
