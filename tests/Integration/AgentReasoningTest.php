<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Agents\RememberingToolAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

uses(RefreshDatabase::class)->beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => __DIR__.'/../../database/migrations',
        '--realpath' => true,
    ])->run();
})->in(__FILE__);

test('models can replay conversation history with tool calls', function (string $provider, string $apiKey, string $model, bool $isReasoning): void {
    requiresApiKey($apiKey);

    $tool = new FixedNumberGenerator;
    $instructions = 'For every request, call the FixedNumberGenerator tool before answering. Answer with one short sentence.';

    $makeAgent = fn (iterable $messages = []): object => new class($instructions, $messages, [$tool], $isReasoning) implements Agent, Conversational, HasProviderOptions, HasTools
    {
        use Promptable;

        public function __construct(
            public string $instructions,
            public iterable $messages,
            public iterable $tools,
            public bool $isReasoning,
        ) {}

        public function instructions(): string
        {
            return $this->instructions;
        }

        public function messages(): iterable
        {
            return $this->messages;
        }

        public function tools(): iterable
        {
            return $this->tools;
        }

        public function providerOptions(Lab|string $provider): array
        {
            $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

            if (! $this->isReasoning) {
                return [];
            }

            return match ($provider) {
                Lab::OpenAI, Lab::Azure => [
                    'reasoning' => ['effort' => 'high'],
                ],
                default => [],
            };
        }
    };

    // First turn: model calls the tool (with or without reasoning blocks)...
    $firstPrompt = 'What fixed number is available?';
    $firstResponse = $makeAgent()->prompt(
        $firstPrompt,
        provider: $provider,
        model: $model,
    );

    expect($firstResponse->toolCalls)->toHaveCount(1)
        ->and($firstResponse->text)->not->toBeEmpty();

    // Second turn: replay full history including any reasoning + tool calls...
    $secondResponse = $makeAgent([
        new UserMessage($firstPrompt),
        ...$firstResponse->messages->all(),
    ])->prompt(
        'Thanks. Confirm the fixed number again.',
        provider: $provider,
        model: $model,
    );

    expect($secondResponse->text)->toContain('72019');
})->with('tool-replay-providers');

test('reasoning conversation roundtrips through database storage', function (): void {
    requiresApiKey('OPENAI_API_KEY');

    $user = (object) ['id' => 1];
    $firstPrompt = 'What fixed number is available?';

    $agent = (new RememberingToolAgent(['reasoning' => ['effort' => 'high']]))
        ->forUser($user);

    $firstResponse = $agent->prompt(
        $firstPrompt,
        provider: 'openai',
        model: 'gpt-6-luna',
    );

    expect($firstResponse->toolCalls)->toHaveCount(1)
        ->and($firstResponse->text)->not->toBeEmpty();

    $conversationId = $agent->currentConversation();

    expect($conversationId)->not->toBeNull();

    $record = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->first();

    expect($record)->not->toBeNull();

    $step = json_decode((string) $record->steps, true)[0];

    expect($step['tool_calls'])->toHaveCount(1)
        ->and($step['tool_calls'][0])->not->toHaveKey('reasoning_id')
        ->and(collect($step['replay_blocks'])->firstWhere('type', 'reasoning'))->not->toBeNull();

    $secondResponse = (new RememberingToolAgent)
        ->continue($conversationId, $user)
        ->prompt(
            'Confirm the fixed number again.',
            provider: 'openai',
            model: 'gpt-4.1',
        );

    expect($secondResponse->text)->toContain('72019');
});

test('anthropic replays a stored turn\'s signed thinking until the instructions change', function (): void {
    requiresApiKey('ANTHROPIC_API_KEY');

    config([
        'ai.conversations.generate_title' => false,
        'ai.providers.anthropic.anthropic_beta' => 'web-fetch-2025-09-10,thinking-binding-controls-2026-08-01',
    ]);

    Http::record();

    $agent = (new class(['thinking' => ['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'error']]]) extends RememberingToolAgent implements Conversational
    {
        public string $prompt = 'Think about which tool fits, then call the FixedNumberGenerator tool before answering. Answer with one short sentence.';

        public function instructions(): string
        {
            return $this->prompt;
        }
    })->forUser((object) ['id' => 1]);

    $agent->prompt('Which fixed number is available? Reason step by step before calling the tool.', provider: 'anthropic', model: 'claude-sonnet-5-5');

    $toolCall = json_decode((string) DB::table('agent_conversation_messages')->where('role', 'assistant')->value('steps'), true)[0]['tool_calls'][0];

    expect($toolCall['thinking_blocks'] ?? [])->not->toBeEmpty();

    $sentThinking = fn (string $prompt): bool => str_contains(json_encode(collect(Http::recorded())
        ->map(fn (array $pair): array => $pair[0]->data())
        ->first(fn (array $body): bool => (last($body['messages'])['content'][0]['text'] ?? null) === $prompt)['messages']), '"thinking"');

    expect($agent->prompt('Confirm the fixed number again.', provider: 'anthropic', model: 'claude-sonnet-5-5')->text)->toContain('72019')
        ->and($sentThinking('Confirm the fixed number again.'))->toBeTrue();

    $agent->prompt = 'Call the FixedNumberGenerator tool before answering. Reply in one sentence.';

    expect($agent->prompt('And once more?', provider: 'anthropic', model: 'claude-sonnet-5-5')->text)->toContain('72019')
        ->and($sentThinking('And once more?'))->toBeFalse();
});
