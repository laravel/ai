<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    $this->customUrl = 'http://localhost:1234';
});

test('ollama text requests use the configured base url', function (): void {
    configureOllamaProvider($this->customUrl);

    Http::fake([
        '*' => Http::response([
            'model' => 'llama3.1:8b',
            'message' => ['role' => 'assistant', 'content' => 'Hello from local model'],
            'done_reason' => 'stop',
            'done' => true,
            'prompt_eval_count' => 1,
            'eval_count' => 1,
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'ollama');

    expect($response->text)->toBe('Hello from local model');

    Http::assertSentCount(1);
    ollamaAssertRequestSent('POST', "{$this->customUrl}/api/chat");
});

test('ollama requests fall back to the default base url', function (): void {
    configureOllamaProvider();

    Http::fake([
        '*' => Http::response([
            'model' => 'llama3.1:8b',
            'message' => ['role' => 'assistant', 'content' => 'Hello from Ollama'],
            'done_reason' => 'stop',
            'done' => true,
            'prompt_eval_count' => 1,
            'eval_count' => 1,
        ]),
    ]);

    $response = agent()->prompt('Hello', provider: 'ollama');

    expect($response->text)->toBe('Hello from Ollama');

    Http::assertSentCount(1);
    ollamaAssertRequestSent('POST', 'http://localhost:11434/api/chat');
});

test('ollama embeddings use the configured base url', function (): void {
    configureOllamaProvider($this->customUrl);

    Http::fake([
        '*' => Http::response([
            'model' => 'nomic-embed-text',
            'embeddings' => [[0.1, 0.2, 0.3]],
            'prompt_eval_count' => 5,
        ]),
    ]);

    Embeddings::for(['Hello world'])->generate(provider: 'ollama', model: 'nomic-embed-text');

    ollamaAssertRequestSent('POST', "{$this->customUrl}/api/embed");
});

function configureOllamaProvider(?string $url = null): void
{
    $config = [
        ...config('ai.providers.ollama'),
        'key' => '',
    ];

    if ($url !== null) {
        $config['url'] = $url;
    } else {
        unset($config['url']);
    }

    config(['ai.providers.ollama' => $config]);
}

function ollamaAssertRequestSent(string $method, string $url): void
{
    Http::assertSent(fn (Request $request): bool => $request->method() === $method
        && $request->url() === $url);
}
