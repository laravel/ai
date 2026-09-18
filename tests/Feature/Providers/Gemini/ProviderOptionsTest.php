<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\ToolChoice;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Tests\Fixtures\Tools\RandomNumberGenerator;

test('provider options are included in generation config', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new ProviderOptionsAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    Http::assertSent(function ($request): bool {
        $body = $request->data();
        $config = $body['generationConfig'] ?? [];

        return isset($config['thinkingConfig'])
            && $config['thinkingConfig']['thinkingBudget'] === 10000;
    });
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    Http::assertSent(function ($request): bool {
        $config = $request->data()['generationConfig'] ?? [];

        return ! isset($config['thinkingConfig']);
    });
});

test('provider options are persisted in tool call follow up requests', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence([
            $this->fakeToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ProviderOptionsWithToolsAgent)->prompt(
        'Generate a random number',
        provider: 'gemini',
    );

    expect($response->text)->toBe('The number is 72019');

    $recorded = Http::recorded();

    expect($recorded)->toHaveCount(2);

    $firstConfig = $recorded[0][0]->data()['generationConfig'] ?? [];
    expect($firstConfig['thinkingConfig']['thinkingBudget'])->toBe(10000);

    $secondConfig = $recorded[1][0]->data()['generationConfig'] ?? [];
    expect($secondConfig)->toHaveKey('thinkingConfig')
        ->and($secondConfig['thinkingConfig']['thinkingBudget'])->toBe(10000);
});

function geminiOptionsAgent(array $options): Agent
{
    return new class($options) implements Agent, HasProviderOptions
    {
        use Promptable;

        public function __construct(private array $options) {}

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return $provider === Lab::Gemini ? $this->options : [];
        }
    };
}

test('nested generationConfig inside providerOptions is flattened into the top-level generationConfig', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent(['generationConfig' => ['temperature' => 0.5, 'topP' => 0.9]])
        ->prompt('Hi', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $config = $request->data()['generationConfig'] ?? [];

        return ($config['temperature'] ?? null) === 0.5
            && ($config['topP'] ?? null) === 0.9
            && ! isset($config['generationConfig']);
    });
});

test('safetySettings is placed at top level of request body, not in generationConfig', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent(['safetySettings' => [['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE']]])
        ->prompt('Hi', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return ($body['safetySettings'][0]['threshold'] ?? null) === 'BLOCK_NONE'
            && ! isset($body['generationConfig']['safetySettings']);
    });
});

test('safetySettings nested inside a generationConfig option is hoisted to the request root', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent(['generationConfig' => [
        'temperature' => 0.2,
        'safetySettings' => [['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH']],
    ]])->prompt('Hi', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return ($body['safetySettings'][0]['threshold'] ?? null) === 'BLOCK_ONLY_HIGH'
            && ($body['generationConfig']['temperature'] ?? null) === 0.2
            && ! isset($body['generationConfig']['safetySettings']);
    });
});

test('cachedContent is placed at top level of request body, not in generationConfig', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent(['cachedContent' => 'cachedContents/test-cache-123'])
        ->prompt('Hi', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['cachedContent'] === 'cachedContents/test-cache-123'
            && ! isset($body['generationConfig']['cachedContent']);
    });
});

test('every top level request field is reachable through provider options', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent([
        'toolConfig' => ['functionCallingConfig' => ['mode' => 'NONE']],
        'serviceTier' => 'priority',
        'store' => false,
        'generationConfig' => ['seed' => 7],
    ])->prompt('Hi', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['toolConfig'] === ['functionCallingConfig' => ['mode' => 'NONE']]
            && $body['serviceTier'] === 'priority'
            && $body['store'] === false
            && $body['generationConfig']['seed'] === 7
            && ! isset($body['generationConfig']['toolConfig']);
    });
});

test('top level fields spelled the way the REST docs spell them are still hoisted', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent([
        'safety_settings' => [['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE']],
        'service_tier' => 'priority',
        'cached_content' => 'cachedContents/test-cache-123',
    ])->prompt('Hi', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return ($body['safetySettings'][0]['threshold'] ?? null) === 'BLOCK_NONE'
            && $body['serviceTier'] === 'priority'
            && $body['cachedContent'] === 'cachedContents/test-cache-123'
            && ! isset($body['generationConfig']);
    });
});

test('a toolConfig option replaces the block built from the tool choice', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    $agent = new class implements Agent, HasProviderOptions, HasTools
    {
        use Promptable;

        public function instructions(): string
        {
            return 'You are a helpful assistant.';
        }

        public function tools(): iterable
        {
            return [new RandomNumberGenerator];
        }

        public function toolChoice(): ToolChoice|string|array|null
        {
            return 'required';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['toolConfig' => ['functionCallingConfig' => ['mode' => 'NONE']]];
        }
    };

    $agent->prompt('Hi', provider: 'gemini');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['toolConfig'] === ['functionCallingConfig' => ['mode' => 'NONE']]
            && ! isset($body['tool_config']);
    });
});
