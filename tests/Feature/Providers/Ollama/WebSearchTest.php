<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

use function Laravel\Ai\agent;

beforeEach(function () {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => 'test-key',
    ]]);
});

test('web search tool is mapped to a function definition', function () {
    Http::fake(['*' => $this->fakeTextResponse('done')]);

    agent(tools: [new WebSearch])->prompt('Search the web', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('function.name', 'web_search');

        return $tool['type'] === 'function'
            && array_key_exists('query', $tool['function']['parameters']['properties'])
            && in_array('query', $tool['function']['parameters']['required'], true);
    });
});

test('web fetch tool is mapped to a function definition', function () {
    Http::fake(['*' => $this->fakeTextResponse('done')]);

    agent(tools: [new WebFetch])->prompt('Fetch a page', provider: 'ollama');

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $tool = collect(data_get($body, 'tools'))->firstWhere('function.name', 'web_fetch');

        return $tool['type'] === 'function'
            && array_key_exists('url', $tool['function']['parameters']['properties'])
            && in_array('url', $tool['function']['parameters']['required'], true);
    });
});

test('web search tool call is executed against the hosted API', function () {
    Http::fake([
        'https://ollama.com/api/web_search*' => Http::response([
            'results' => [
                ['title' => 'Laravel', 'url' => 'https://laravel.com', 'content' => 'The PHP framework.'],
            ],
        ]),
        'http://localhost:11434/*' => Http::sequence([
            fakeOllamaWebSearchToolCall(['query' => 'laravel']),
            $this->fakeTextResponse('Laravel is a PHP framework.'),
        ]),
    ]);

    $response = agent(tools: [new WebSearch])->prompt('What is Laravel?', provider: 'ollama');

    expect($response->text)->toBe('Laravel is a PHP framework.');

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'ollama.com/api/web_search')
            && $request['query'] === 'laravel'
            && $request->hasHeader('Authorization', 'Bearer test-key');
    });

    $followUp = json_decode(Http::recorded()->last()[0]->body(), true);
    $toolMsg = collect($followUp['messages'])->first(fn ($m) => $m['role'] === 'tool');

    expect($toolMsg['tool_name'])->toBe('web_search')
        ->and($toolMsg['content'])->toContain('laravel.com');
});

test('web fetch tool call is executed against the hosted API', function () {
    Http::fake([
        'https://ollama.com/api/web_fetch*' => Http::response([
            'title' => 'Laravel',
            'content' => 'The PHP framework for web artisans.',
            'links' => ['https://laravel.com/docs'],
        ]),
        'http://localhost:11434/*' => Http::sequence([
            fakeOllamaWebToolCall('web_fetch', ['url' => 'https://laravel.com']),
            $this->fakeTextResponse('Fetched the page.'),
        ]),
    ]);

    $response = agent(tools: [new WebFetch])->prompt('Read laravel.com', provider: 'ollama');

    expect($response->text)->toBe('Fetched the page.');

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'ollama.com/api/web_fetch')
            && $request['url'] === 'https://laravel.com';
    });
});

test('web search without an API key throws', function () {
    config(['ai.providers.ollama' => [
        ...config('ai.providers.ollama'),
        'key' => '',
    ]]);

    Http::fake([
        'http://localhost:11434/*' => Http::sequence([
            fakeOllamaWebSearchToolCall(['query' => 'laravel']),
            $this->fakeTextResponse('Done.'),
        ]),
    ]);

    expect(fn () => agent(tools: [new WebSearch])->prompt('Search', provider: 'ollama'))
        ->toThrow(AiException::class);
});

test('web search with allowed domains throws', function () {
    Http::fake(['*' => $this->fakeTextResponse('done')]);

    expect(fn () => agent(tools: [(new WebSearch)->allow(['laravel.com'])])->prompt('Search', provider: 'ollama'))
        ->toThrow(RuntimeException::class, 'Ollama web search does not support restricting allowed domains.');
});

test('web search with a location throws', function () {
    Http::fake(['*' => $this->fakeTextResponse('done')]);

    expect(fn () => agent(tools: [(new WebSearch)->location(city: 'Indore')])->prompt('Search', provider: 'ollama'))
        ->toThrow(RuntimeException::class, 'Ollama web search does not support location-based results.');
});

test('web fetch with allowed domains throws', function () {
    Http::fake(['*' => $this->fakeTextResponse('done')]);

    expect(fn () => agent(tools: [(new WebFetch)->allow(['laravel.com'])])->prompt('Fetch', provider: 'ollama'))
        ->toThrow(RuntimeException::class, 'Ollama web fetch does not support restricting allowed domains.');
});

test('web search max results is capped at ten', function () {
    Http::fake([
        'https://ollama.com/api/web_search*' => Http::response(['results' => []]),
        'http://localhost:11434/*' => Http::sequence([
            fakeOllamaWebSearchToolCall(['query' => 'laravel', 'max_results' => 50]),
            $this->fakeTextResponse('Done.'),
        ]),
    ]);

    agent(tools: [new WebSearch])->prompt('What is Laravel?', provider: 'ollama');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ollama.com/api/web_search')
        ? $request['max_results'] === 10
        : true);
});

test('streaming executes a web search tool call', function () {
    Http::fake([
        'https://ollama.com/api/web_search*' => Http::response([
            'results' => [
                ['title' => 'Laravel', 'url' => 'https://laravel.com', 'content' => 'The PHP framework.'],
            ],
        ]),
        'http://localhost:11434/*' => Http::sequence([
            Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunkWithToolCalls([
                        $this->toolCallChunk('call_ws', 'web_search', ['query' => 'laravel']),
                    ]),
                ]),
            ),
            Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunk('Laravel is a PHP framework.'),
                    $this->chatChunk('', true, 'stop', ['prompt_eval_count' => 20, 'eval_count' => 10]),
                ]),
            ),
        ]),
    ]);

    $events = [];

    foreach (agent(tools: [new WebSearch])->stream('What is Laravel?', provider: 'ollama') as $event) {
        $events[] = $event;
    }

    $toolResults = array_values(array_filter($events, fn ($e) => $e instanceof ToolResultEvent));

    expect($toolResults)->not->toBeEmpty()
        ->and($toolResults[0]->toolResult->name)->toBe('web_search')
        ->and($toolResults[0]->toolResult->result)->toContain('laravel.com');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ollama.com/api/web_search')
        && $request['query'] === 'laravel');
});

function fakeOllamaWebToolCall(string $name, array $arguments): PromiseInterface
{
    return Http::response([
        'model' => 'llama3.1:8b',
        'message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_'.$name,
                'function' => ['name' => $name, 'arguments' => (object) $arguments],
            ]],
        ],
        'done_reason' => 'tool_calls',
        'done' => true,
        'prompt_eval_count' => 10,
        'eval_count' => 5,
    ]);
}

function fakeOllamaWebSearchToolCall(array $arguments): PromiseInterface
{
    return fakeOllamaWebToolCall('web_search', $arguments);
}
