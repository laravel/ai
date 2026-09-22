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

test('provider options are included in the generation config', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new ProviderOptionsAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    expect(sentRequest()->data()['generation_config'])->toMatchArray(['thinking_level' => 'high']);
});

test('request body does not contain provider options when agent does not implement interface', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'gemini',
    );

    expect(sentRequest()->data()['generation_config'] ?? [])->not->toHaveKey('thinking_level');
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

    expect($recorded)->toHaveCount(2)
        ->and($recorded[0][0]->data()['generation_config']['thinking_level'])->toBe('high')
        ->and($recorded[1][0]->data()['generation_config']['thinking_level'])->toBe('high');
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

test('nested generation_config inside providerOptions is flattened into the top-level generation config', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent(['generation_config' => ['temperature' => 0.5, 'top_p' => 0.9]])
        ->prompt('Hi', provider: 'gemini');

    expect(sentRequest()->data()['generation_config'])
        ->toMatchArray(['temperature' => 0.5, 'top_p' => 0.9])
        ->not->toHaveKey('generation_config');
});

test('safety settings are placed at the top level of the request body', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent(['safety_settings' => [['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE']]])
        ->prompt('Hi', provider: 'gemini');

    expect(sentRequest()->data()['safety_settings'][0])->toMatchArray(['threshold' => 'BLOCK_NONE'])
        ->and(sentRequest()->data()['generation_config'] ?? [])->not->toHaveKey('safety_settings');
});

test('safety settings nested inside a generation_config option are hoisted to the request root', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent(['generation_config' => [
        'temperature' => 0.2,
        'safety_settings' => [['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH']],
    ]])->prompt('Hi', provider: 'gemini');

    expect(sentRequest()->data()['safety_settings'][0])->toMatchArray(['threshold' => 'BLOCK_ONLY_HIGH'])
        ->and(sentRequest()->data()['generation_config'])->toMatchArray(['temperature' => 0.2])
        ->not->toHaveKey('safety_settings');
});

test('every top level request field is reachable through provider options', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent([
        'service_tier' => 'flex',
        'store' => true,
        'labels' => ['team' => 'search'],
        'generation_config' => ['seed' => 7],
    ])->prompt('Hi', provider: 'gemini');

    expect(sentRequest()->data())->toMatchArray([
        'service_tier' => 'flex',
        'store' => true,
        'labels' => ['team' => 'search'],
    ])->and(sentRequest()->data()['generation_config'])
        ->toMatchArray(['seed' => 7])
        ->not->toHaveKey('service_tier');
});

test('top level fields spelled in camel case are still hoisted', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent([
        'safetySettings' => [['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE']],
        'serviceTier' => 'priority',
        'previousInteractionId' => 'int_abc',
    ])->prompt('Hi', provider: 'gemini');

    expect(sentRequest()->data())->toMatchArray([
        'service_tier' => 'priority',
        'previous_interaction_id' => 'int_abc',
    ])->not->toHaveKey('generation_config')
        ->and(sentRequest()->data()['safety_settings'][0])->toMatchArray(['threshold' => 'BLOCK_NONE']);
});

test('a store option overrides the stateless default', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => $this->fakeTextResponse(),
    ]);

    geminiOptionsAgent(['store' => true])->prompt('Hi', provider: 'gemini');

    expect(sentRequest()->data())->toMatchArray(['store' => true]);
});

test('a tool choice option replaces the value built from the agent tool choice', function (): void {
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
            return ['generation_config' => ['tool_choice' => 'none']];
        }
    };

    $agent->prompt('Hi', provider: 'gemini');

    expect(sentRequest()->data()['generation_config'])->toMatchArray(['tool_choice' => 'none']);
});
