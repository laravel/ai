<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

function cachedSystemPromptAgent(array|string $cacheControl = ['type' => 'ephemeral']): object
{
    return new class($cacheControl) implements Agent, HasProviderOptions
    {
        use Promptable;

        public function __construct(public array|string $cacheControl) {}

        public function instructions(): string
        {
            return 'You are a helpful assistant with a large knowledge base.';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['cache_control' => $this->cacheControl];
        }
    };
}

test('provider options are included in anthropic request body', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new ProviderOptionsAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $body = $request->data();

        return isset($body['thinking'])
            && $body['thinking']['type'] === 'enabled'
            && $body['thinking']['budget_tokens'] === 10000;
    });
});

test('request body does not contain provider options when agent does not implement interface', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        return ! isset($request->data()['thinking']);
    });
});

test('provider options are persisted in tool call follow up requests', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ProviderOptionsWithToolsAgent)->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    expect($response->text)->toBe('The number is 72019');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $firstBody = $recorded[0][0]->data();
    expect($firstBody['thinking'])->toMatchArray(['type' => 'enabled', 'budget_tokens' => 10000]);

    $secondBody = $recorded[1][0]->data();
    expect($secondBody)->toHaveKey('thinking')
        ->and($secondBody['thinking'])->toMatchArray(['type' => 'enabled', 'budget_tokens' => 10000]);
});

test('cache_control formats system prompt as a content block (5m default)', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    cachedSystemPromptAgent()->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return is_array($body['system'])
            && $body['system'][0]['type'] === 'text'
            && str_contains($body['system'][0]['text'], 'helpful')
            && $body['system'][0]['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('cache_control with ttl=1h is sent verbatim on the system block', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    cachedSystemPromptAgent(['type' => 'ephemeral', 'ttl' => '1h'])->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['system'][0]['cache_control'] === ['type' => 'ephemeral', 'ttl' => '1h'];
    });
});

test('cache_control is removed from the top-level request body', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    cachedSystemPromptAgent()->prompt('Hi', provider: 'anthropic');

    Http::assertSent(fn ($request) => ! isset($request->data()['cache_control']));
});

test('system prompt remains a string when cache_control absent', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'anthropic');

    Http::assertSent(fn ($request) => is_string($request->data()['system']));
});

test('cache_control accepts string shorthand on system prompt', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    cachedSystemPromptAgent('ephemeral')->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request) {
        return $request->data()['system'][0]['cache_control'] === ['type' => 'ephemeral'];
    });
});

test('tool implementing HasProviderOptions attaches cache_control to that tool', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    $cachedTool = new class implements HasProviderOptions, Tool
    {
        public function description(): string
        {
            return 'A tool that opts into Anthropic prompt caching.';
        }

        public function handle(ToolRequest $request): string
        {
            return 'cached';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['cache_control' => ['type' => 'ephemeral']];
        }
    };

    $agent = new class($cachedTool) implements Agent, HasTools
    {
        use Promptable;

        public function __construct(private object $cachedTool) {}

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function tools(): iterable
        {
            return [new FixedNumberGenerator, $this->cachedTool];
        }
    };

    $agent->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $tools = $request->data()['tools'] ?? [];

        if (count($tools) !== 2) {
            return false;
        }

        return ! isset($tools[0]['cache_control'])
            && ($tools[1]['cache_control'] ?? null) === ['type' => 'ephemeral'];
    });
});

test('tool_result_cache_type attaches cache_control to the last tool_result content block', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $agent = new class implements Agent, HasProviderOptions, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant that generates numbers.';
        }

        public function tools(): iterable
        {
            return [new FixedNumberGenerator];
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['tool_result_cache_type' => 'ephemeral'];
        }
    };

    $agent->prompt('Generate a random number', provider: 'anthropic');

    $recorded = Http::recorded();
    $secondBody = $recorded[1][0]->data();

    $toolResultMessage = collect($secondBody['messages'])
        ->first(fn ($m) => is_array($m['content'])
            && ($m['content'][0]['type'] ?? null) === 'tool_result');

    expect($toolResultMessage)->not->toBeNull()
        ->and($toolResultMessage['content'][0]['cache_control'])
        ->toBe(['type' => 'ephemeral']);

    expect($secondBody)->not->toHaveKey('tool_result_cache_type');
});

test('ToolResultMessage::withProviderOptions attaches cache_control to its last content block', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    $agent = new class implements Agent, Conversational
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function messages(): iterable
        {
            $results = collect([
                new ToolResult('toolu_1', 'FixedNumberGenerator', [], 72019),
                new ToolResult('toolu_2', 'FixedNumberGenerator', [], 81234),
            ]);

            return [
                new Message(role: 'user', content: 'Generate two numbers'),
                new AssistantMessage('', collect([
                    new ToolCall('toolu_1', 'FixedNumberGenerator', [], 'toolu_1'),
                    new ToolCall('toolu_2', 'FixedNumberGenerator', [], 'toolu_2'),
                ])),
                (new ToolResultMessage($results))->withProviderOptions([
                    'cache_control' => ['type' => 'ephemeral'],
                ]),
            ];
        }
    };

    $agent->prompt('Follow up', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $messages = $request->data()['messages'];
        $toolResults = collect($messages)
            ->first(fn ($m) => is_array($m['content']) && ($m['content'][0]['type'] ?? null) === 'tool_result');

        if ($toolResults === null) {
            return false;
        }

        $content = $toolResults['content'];

        return ! isset($content[0]['cache_control'])
            && ($content[1]['cache_control'] ?? null) === ['type' => 'ephemeral'];
    });
});
