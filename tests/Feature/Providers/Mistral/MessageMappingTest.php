<?php

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredDocument;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);
});

test('user message maps to chat format', function (): void {
    Http::fake(['*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('What is Laravel?', provider: 'mistral');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $userMsg = collect($body['messages'])->firstWhere('role', 'user');

        return $userMsg['content'] === 'What is Laravel?';
    });
});

test('assistant message with tool calls maps correctly', function (): void {
    Http::fake([
        '*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'mistral');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $followUpBody = json_decode((string) $recorded[1][0]->body(), true);

    $assistantMsg = collect($followUpBody['messages'])->firstWhere('role', 'assistant');
    $toolMsg = collect($followUpBody['messages'])->firstWhere('role', 'tool');

    expect($assistantMsg)->not->toBeNull()
        ->and($toolMsg)->not->toBeNull()
        ->and($assistantMsg['tool_calls'])->not->toBeEmpty()
        ->and($assistantMsg['tool_calls'][0]['function']['name'])->toBe('FixedNumberGenerator')
        ->and($toolMsg['tool_call_id'])->toBe($assistantMsg['tool_calls'][0]['id']);
});

test('image attachment maps to image url', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    $image = new RemoteImage('https://example.com/image.png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request): bool {
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

test('base64 image attachment maps to data uri', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    $image = new Base64Image(base64_encode('fake-image-data'), 'image/png');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$image],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        if (! is_array($content)) {
            return false;
        }

        $imageBlock = collect($content)->firstWhere('type', 'image_url');

        return $imageBlock !== null
            && str_starts_with((string) $imageBlock['image_url']['url'], 'data:image/png;base64,');
    });
});

test('local image attachment without explicit mime type detects mime from file', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [new LocalImage(__DIR__.'/../../../Fixtures/Images/red.png')],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        if (! is_array($content)) {
            return false;
        }

        $imageBlock = collect($content)->firstWhere('type', 'image_url');

        return $imageBlock !== null
            && str_starts_with((string) $imageBlock['image_url']['url'], 'data:image/png;base64,')
            && ! str_contains((string) $imageBlock['image_url']['url'], 'data:;base64,');
    });
});

test('remote document maps to document url', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

    $document = new RemoteDocument('https://example.com/report.pdf');

    agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [$document],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request): bool {
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

test('base64 document maps to an inline document url', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

    $document = (new Base64Document(base64_encode('fake-pdf-content'), 'application/pdf'))->as('report.pdf');

    agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [$document],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        return collect($content)->firstWhere('type', 'document_url') === [
            'type' => 'document_url',
            'document_url' => 'data:application/pdf;base64,'.base64_encode('fake-pdf-content'),
            'document_name' => 'report.pdf',
        ];
    });
});

test('local document maps to an inline document url', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

    $path = __DIR__.'/../../../Fixtures/document.txt';

    agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [new LocalDocument($path)],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request) use ($path): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        return collect($content)->firstWhere('type', 'document_url') === [
            'type' => 'document_url',
            'document_url' => 'data:text/plain;base64,'.base64_encode(file_get_contents($path)),
            'document_name' => 'document.txt',
        ];
    });
});

test('stored document maps to an inline document url', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see a document')]);
    Storage::fake('docs');
    Storage::disk('docs')->put('notes.txt', 'stored text contents');

    agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [new StoredDocument('notes.txt', 'docs')],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        return collect($content)->firstWhere('type', 'document_url') === [
            'type' => 'document_url',
            'document_url' => 'data:text/plain;base64,'.base64_encode('stored text contents'),
            'document_name' => 'notes.txt',
        ];
    });
});

test('uploaded document maps to an inline document url', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

    $upload = UploadedFile::fake()->createWithContent('notes.txt', 'uploaded text contents');

    agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [$upload],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        return collect($content)->firstWhere('type', 'document_url') === [
            'type' => 'document_url',
            'document_url' => 'data:text/plain;base64,'.base64_encode('uploaded text contents'),
            'document_name' => 'notes.txt',
        ];
    });
});

test('uploaded avif image maps to an image url', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see an image')]);

    $upload = UploadedFile::fake()->createWithContent('shot.avif', 'avif-bytes');

    agent('You are helpful.')->prompt(
        'What is in this image?',
        attachments: [$upload],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        return collect($content)->firstWhere('type', 'image_url') === [
            'type' => 'image_url',
            'image_url' => ['url' => 'data:image/avif;base64,'.base64_encode('avif-bytes')],
        ];
    });
});

test('document without a name or mime type falls back to a pdf data uri', function (): void {
    Http::fake(['*' => $this->fakeTextResponse('I see a document')]);

    agent('You are helpful.')->prompt(
        'What is in this document?',
        attachments: [Document::fromString('nameless contents')],
        provider: 'mistral',
    );

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);
        $content = $body['messages'][1]['content'] ?? $body['messages'][0]['content'];

        return collect($content)->firstWhere('type', 'document_url') === [
            'type' => 'document_url',
            'document_url' => 'data:application/pdf;base64,'.base64_encode('nameless contents'),
            'document_name' => 'document',
        ];
    });
});

test('unsupported attachment type throws', function (): void {
    Http::fake(['*' => $this->fakeTextResponse()]);

    expect(fn () => agent('You are helpful.')->prompt(
        'What is in this recording?',
        attachments: [new LocalAudio(__DIR__.'/../../../Fixtures/audio.mp3')],
        provider: 'mistral',
    ))->toThrow(
        InvalidArgumentException::class,
        'Mistral only supports image and document attachments. Unsupported attachment type ['.LocalAudio::class.'].',
    );
});

test('system instructions are in messages array', function (): void {
    Http::fake(['*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: 'mistral');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        $systemMsg = collect($body['messages'])->firstWhere('role', 'system');

        return $systemMsg !== null
            && str_contains((string) $systemMsg['content'], 'helpful assistant');
    });
});
