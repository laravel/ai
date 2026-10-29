<?php

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Gateway\ClaudeCli\ClaudeCliGateway;
use Laravel\Ai\Gateway\StepContext;
use Tests\Feature\Providers\ClaudeCli\ClaudeCliHelpers;
use Tests\Fixtures\Agents\AssistantAgent;

uses(ClaudeCliHelpers::class);

afterEach(function (): void {
    $this->cleanUpFakeCliScripts();
});

describe('text generation', function (): void {
    test('returns the text from the assistant message', function (): void {
        $binary = $this->fakeCli($this->cliSuccessLines('Hello, world!'));
        $this->useCliBinary($binary);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'claude-cli');

        expect($response->text)->toBe('Hello, world!');
    });

    test('response meta contains the provider name', function (): void {
        $binary = $this->fakeCli($this->cliSuccessLines('Hi'));
        $this->useCliBinary($binary);

        $response = (new AssistantAgent)->prompt('Hello', provider: 'claude-cli');

        expect($response->meta->provider)->toBe('claude-cli');
    });

    test('response meta contains the model', function (): void {
        $binary = $this->fakeCli($this->cliSuccessLines('Hi', 'sess-1', 'claude-sonnet-5-5'));
        $this->useCliBinary($binary);

        $response = (new AssistantAgent)->prompt('Hello', provider: 'claude-cli', model: 'claude-sonnet-5-5');

        expect($response->meta->model)->toBe('claude-sonnet-5-5');
    });

    test('throws StreamErrorException when the CLI reports an error result', function (): void {
        $binary = $this->fakeCli($this->cliErrorLines('Auth failed'));
        $this->useCliBinary($binary);

        expect(fn () => (new AssistantAgent)->prompt('Hi', provider: 'claude-cli'))
            ->toThrow(StreamErrorException::class);
    });

    test('throws InvalidArgumentException when structured output is requested', function (): void {
        $gateway = new ClaudeCliGateway(app(Dispatcher::class));
        $provider = app(AiManager::class)->textProvider('claude-cli');

        expect(fn () => $gateway->generateTextStep(
            provider: $provider,
            model: 'claude-sonnet-5-5',
            instructions: null,
            messages: [],
            tools: [],
            schema: ['type' => 'object'],
            options: null,
            timeout: null,
            stepContext: new StepContext,
        ))->toThrow(InvalidArgumentException::class);
    });
});
