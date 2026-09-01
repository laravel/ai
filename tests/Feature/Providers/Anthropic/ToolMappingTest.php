<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\CodeExecution;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Tests\Fixtures\Agents\NamedToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

test('tool parameters are not wrapped in schema definition', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $tools = $request->data()['tools'] ?? [];

        foreach ($tools as $tool) {
            if ($tool['name'] === 'FixedNumberGenerator') {
                $properties = (array) ($tool['input_schema']['properties'] ?? []);

                return $tool['input_schema']['type'] === 'object'
                    && ! isset($properties['schema_definition']);
            }
        }

        return false;
    });
});

test('unsupported provider tool throws logic exception', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    agent(
        'Test unsupported tool',
        tools: [new FileSearch(['store_1'])],
    )->prompt(
        'Search for something',
        provider: 'anthropic',
    );
})->throws(LogicException::class, 'is not supported by Anthropic');

test('tool with a name() method emits the declared name', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new NamedToolAgent('aliased_tool'))->prompt('Search', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $names = collect($request->data()['tools'] ?? [])->pluck('name')->all();

        return in_array('aliased_tool', $names, true);
    });
});

test('web search tool sends allowed_domains', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [(new WebSearch)->allow(['laravel.com', 'php.net'])])
        ->prompt('Search', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_search');

        return data_get($tool, 'allowed_domains') === ['laravel.com', 'php.net'];
    });
});

test('web search tool forwards anthropic provider options into the tool payload', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [
        (new WebSearch)->withProviderOptions(['blocked_domains' => ['spam.com']]),
    ])->prompt('Search', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_search');

        return data_get($tool, 'blocked_domains') === ['spam.com'];
    });
});

test('web search tool sends user_location when location is set', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [(new WebSearch)->location(city: 'Warsaw', country: 'PL')])
        ->prompt('Search', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_search');

        return data_get($tool, 'user_location.type') === 'approximate'
            && data_get($tool, 'user_location.city') === 'Warsaw'
            && data_get($tool, 'user_location.country') === 'PL';
    });
});

test('web search tool omits user_location when no location set', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [new WebSearch])->prompt('Search', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_search');

        return ! array_key_exists('user_location', $tool);
    });
});

test('web fetch tool sends allowed_domains', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [(new WebFetch)->allow(['laravel.com', 'php.net'])])
        ->prompt('Fetch', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_fetch');

        return data_get($tool, 'type') === 'web_fetch_20250910'
            && data_get($tool, 'allowed_domains') === ['laravel.com', 'php.net'];
    });
});

test('web fetch tool omits max_uses when none is set', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [new WebFetch])->prompt('Fetch', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_fetch');

        return $tool !== null && ! array_key_exists('max_uses', $tool);
    });
});

test('web fetch tool forwards provider options', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    $fetch = (new WebFetch)->withProviderOptions([
        'citations' => ['enabled' => true],
        'max_content_tokens' => 50000,
    ]);

    agent(tools: [$fetch])->prompt('Fetch', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_fetch');

        return data_get($tool, 'citations.enabled') === true
            && data_get($tool, 'max_content_tokens') === 50000;
    });
});

test('web fetch tool result surfaces the fetched url as a citation', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_fetch_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                ['type' => 'text', 'text' => "I'll fetch that."],
                [
                    'type' => 'server_tool_use',
                    'id' => 'srvtoolu_1',
                    'name' => 'web_fetch',
                    'input' => (object) ['url' => 'https://example.com/article'],
                ],
                [
                    'type' => 'web_fetch_tool_result',
                    'tool_use_id' => 'srvtoolu_1',
                    'content' => [
                        'type' => 'web_fetch_result',
                        'url' => 'https://example.com/article',
                        'content' => [
                            'type' => 'document',
                            'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => 'Full text.'],
                            'title' => 'Article Title',
                            'citations' => ['enabled' => true],
                        ],
                        'retrieved_at' => '2026-08-17T10:30:00Z',
                    ],
                ],
                ['type' => 'text', 'text' => 'The article argues X.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = agent(tools: [(new WebFetch)->withProviderOptions(['citations' => ['enabled' => true]])])
        ->prompt('Fetch it', provider: 'anthropic');

    expect($response->meta->citations)->toHaveCount(1)
        ->and($response->meta->citations[0]->url)->toBe('https://example.com/article')
        ->and($response->meta->citations[0]->title)->toBe('Article Title');
});

test('web fetch tool result skips citations for a failed fetch', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_fetch_2',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [
                [
                    'type' => 'web_fetch_tool_result',
                    'tool_use_id' => 'srvtoolu_1',
                    'content' => ['type' => 'web_fetch_tool_result_error', 'error_code' => 'url_not_accessible'],
                ],
                ['type' => 'text', 'text' => "I couldn't reach that page."],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);

    $response = agent(tools: [new WebFetch])->prompt('Fetch it', provider: 'anthropic');

    expect($response->meta->citations)->toBeEmpty();
});

test('web fetch tool forwards a custom max_uses', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [(new WebFetch)->max(3)])->prompt('Fetch', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_fetch');

        return data_get($tool, 'max_uses') === 3;
    });
});

test('empty schema still includes input schema with type object', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request): bool {
        $tools = $request->data()['tools'] ?? [];

        foreach ($tools as $tool) {
            if ($tool['name'] === 'FixedNumberGenerator') {
                return isset($tool['input_schema'])
                    && $tool['input_schema']['type'] === 'object'
                    && isset($tool['input_schema']['properties']);
            }
        }

        return false;
    });
});

test('code execution tool sends dated type and name', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [new CodeExecution])->prompt('Run some code', provider: 'anthropic');

    Http::assertSent(function ($request): bool {
        $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'code_execution');

        return data_get($tool, 'type') === 'code_execution_20250825';
    });
});
