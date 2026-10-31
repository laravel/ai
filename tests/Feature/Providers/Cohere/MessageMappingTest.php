<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Messages\Message;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.cohere' => [
        ...config('ai.providers.cohere'),
        'key' => 'test-key',
    ]]);
});

test('conversation history maps to chat messages', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('Your name is Sam.')]);

    agent('Be concise.', messages: [
        new Message(role: 'user', content: 'My name is Sam.'),
        new Message(role: 'assistant', content: 'Nice to meet you, Sam.'),
    ])->prompt('What is my name?', provider: 'cohere');

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['messages'] === [
        ['role' => 'system', 'content' => 'Be concise.'],
        ['role' => 'user', 'content' => 'My name is Sam.'],
        ['role' => 'assistant', 'content' => 'Nice to meet you, Sam.'],
        ['role' => 'user', 'content' => 'What is my name?'],
    ]);
});

test('assistant tool calls and tool results are replayed in the follow up request', function (): void {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse('FixedNumberGenerator', 'call_1'),
            $this->fakeTextResponse('The random number generated is 72019.'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a random number', provider: 'cohere');

    $messages = json_decode((string) Http::recorded()[1][0]->body(), true)['messages'];

    expect(collect($messages)->firstWhere('role', 'assistant'))->toBe([
        'role' => 'assistant',
        'tool_calls' => [[
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '{}'],
        ]],
    ])->and(collect($messages)->firstWhere('role', 'tool'))->toBe([
        'role' => 'tool',
        'tool_call_id' => 'call_1',
        'content' => '72019',
    ]);
});

test('remote image attachment maps to image url', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new RemoteImage('https://example.com/image.png')],
        provider: 'cohere',
    );

    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true)['messages'][1]['content'] === [
        ['type' => 'text', 'text' => 'What is in this image?'],
        ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png']],
    ]);
});

test('base64 image attachment maps to data uri', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new Base64Image(base64_encode('fake-image-data'), 'image/png')],
        provider: 'cohere',
    );

    Http::assertSent(function (Request $request): bool {
        $content = json_decode($request->body(), true)['messages'][1]['content'];

        return collect($content)->firstWhere('type', 'image_url') === [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:image/png;base64,'.base64_encode('fake-image-data')],
        ];
    });
});

test('uploaded image maps to data uri', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [UploadedFile::fake()->createWithContent('photo.png', 'png-bytes')],
        provider: 'cohere',
    );

    Http::assertSent(function (Request $request): bool {
        $content = json_decode($request->body(), true)['messages'][1]['content'];

        return collect($content)->firstWhere('type', 'image_url') === [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:image/png;base64,'.base64_encode('png-bytes')],
        ];
    });
});

test('document attachments throw', function (): void {
    Http::fake(['*' => $this->fakeTextResponse()]);

    expect(fn () => agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [Document::fromString('contents', 'text/plain')],
        provider: 'cohere',
    ))->toThrow(
        InvalidArgumentException::class,
        'Cohere does not support document attachments. Only image attachments are supported.',
    );

    Http::assertNothingSent();
});
