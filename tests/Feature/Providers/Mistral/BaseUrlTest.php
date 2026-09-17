<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Audio;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Stores;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.mistral' => [
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
    ]]);

    $this->customUrl = 'http://localhost:1234/v1';
});

test('mistral requests use the configured base url', function (): void {
    configureMistralProvider($this->customUrl);

    Http::fake([
        '*' => $this->fakeTextResponse('Hello from custom'),
    ]);

    $response = agent()->prompt('Hello', provider: 'mistral');

    expect($response->text)->toBe('Hello from custom');

    Http::assertSentCount(1);
    mistralAssertRequestSent('POST', "{$this->customUrl}/chat/completions");
});

test('mistral requests fall back to the default base url', function (): void {
    Http::fake([
        '*' => $this->fakeTextResponse('Hello from Mistral'),
    ]);

    $response = agent()->prompt('Hello', provider: 'mistral');

    expect($response->text)->toBe('Hello from Mistral');

    Http::assertSentCount(1);
    mistralAssertRequestSent('POST', 'https://api.mistral.ai/v1/chat/completions');
});

test('mistral audio requests use the configured base url', function (): void {
    configureMistralProvider($this->customUrl);

    Http::fake([
        '*' => Http::response(['audio_data' => base64_encode('fake-audio-bytes')]),
    ]);

    Audio::of('Hello')->generate(provider: 'mistral');

    Http::assertSentCount(1);
    mistralAssertRequestSent('POST', "{$this->customUrl}/audio/speech");
});

test('mistral file requests use the configured base url', function (): void {
    configureMistralProvider($this->customUrl);

    Http::fake(fn (Request $request) => match ([$request->method(), $request->url()]) {
        ['POST', "{$this->customUrl}/files"] => Http::response(['id' => 'file-123']),
        ['GET', "{$this->customUrl}/files/file-123"] => Http::response(['id' => 'file-123', 'mimetype' => 'text/plain']),
        ['DELETE', "{$this->customUrl}/files/file-123"] => Http::response(['id' => 'file-123', 'deleted' => true]),
        default => Http::response(['unexpected_url' => $request->url()], 500),
    });

    $stored = Files::put(
        Document::fromString('Hello, World!', 'text/plain')->as('hello.txt'),
        provider: 'mistral',
    );

    $retrieved = Files::get($stored->id, provider: 'mistral');

    Files::delete($stored->id, provider: 'mistral');

    expect($stored->id)->toBe('file-123')
        ->and($retrieved->id)->toBe('file-123');

    Http::assertSentCount(3);
    mistralAssertRequestSent('POST', "{$this->customUrl}/files");
    mistralAssertRequestSent('GET', "{$this->customUrl}/files/file-123");
    mistralAssertRequestSent('DELETE', "{$this->customUrl}/files/file-123");
});

test('mistral store requests use the configured base url', function (): void {
    configureMistralProvider($this->customUrl);

    Http::fake(fn (Request $request) => match ([$request->method(), $request->url()]) {
        ['POST', "{$this->customUrl}/libraries"] => Http::response(['id' => 'lib-123', 'name' => 'Local Store', 'nb_documents' => 0]),
        ['GET', "{$this->customUrl}/libraries/lib-123"] => Http::response(['id' => 'lib-123', 'name' => 'Local Store', 'nb_documents' => 0]),
        ['POST', "{$this->customUrl}/libraries/lib-123/documents"] => Http::response(['id' => 'doc-123'], 201),
        ['DELETE', "{$this->customUrl}/libraries/lib-123/documents/doc-123"] => Http::response([], 204),
        ['DELETE', "{$this->customUrl}/libraries/lib-123"] => Http::response(['id' => 'lib-123']),
        default => Http::response(['unexpected_url' => $request->url()], 500),
    });

    $store = Stores::create('Local Store', provider: 'mistral');
    $document = $store->add(Document::fromString('Hello, World!', 'text/plain')->as('hello.txt'));
    $removed = $store->remove($document->id());
    $deleted = $store->delete();

    expect($store->id)->toBe('lib-123')
        ->and($store->name)->toBe('Local Store')
        ->and($document->id())->toBe('doc-123')
        ->and($removed)->toBeTrue()
        ->and($deleted)->toBeTrue();

    Http::assertSentCount(5);
    mistralAssertRequestSent('POST', "{$this->customUrl}/libraries");
    mistralAssertRequestSent('GET', "{$this->customUrl}/libraries/lib-123");
    mistralAssertRequestSent('POST', "{$this->customUrl}/libraries/lib-123/documents");
    mistralAssertRequestSent('DELETE', "{$this->customUrl}/libraries/lib-123/documents/doc-123");
    mistralAssertRequestSent('DELETE', "{$this->customUrl}/libraries/lib-123");
});

test('mistral file search requests use the configured base url', function (): void {
    configureMistralProvider($this->customUrl);

    Http::fake([
        '*' => Http::response([
            'object' => 'conversation.response',
            'conversation_id' => 'conv-123',
            'outputs' => [['object' => 'entry', 'type' => 'message.output', 'role' => 'assistant', 'content' => 'Hello from custom']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]),
    ]);

    $response = agent(tools: [new FileSearch(['lib-123'])])->prompt('Hello', provider: 'mistral');

    expect($response->text)->toBe('Hello from custom');

    Http::assertSentCount(1);
    mistralAssertRequestSent('POST', "{$this->customUrl}/conversations");
});

function configureMistralProvider(?string $url = null): void
{
    config(['ai.providers.mistral' => array_filter([
        ...config('ai.providers.mistral'),
        'key' => 'test-key',
        'url' => $url,
    ])]);
}

function mistralAssertRequestSent(string $method, string $url): void
{
    Http::assertSent(fn (Request $request): bool => $request->method() === $method
        && $request->url() === $url);
}
