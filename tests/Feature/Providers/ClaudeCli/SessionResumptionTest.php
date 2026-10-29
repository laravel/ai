<?php

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\Gateway\ClaudeCli\ClaudeCliGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Tests\Feature\Providers\ClaudeCli\ClaudeCliHelpers;

uses(ClaudeCliHelpers::class);

afterEach(function (): void {
    $this->cleanUpFakeCliScripts();
});

describe('session resumption', function (): void {
    test('the session ID from the init event is returned as the continuation token', function (): void {
        $binary = $this->fakeCli($this->cliSuccessLines('Hi', 'sess-abc123'));
        $this->useCliBinary($binary);

        $gateway = new ClaudeCliGateway(app(Dispatcher::class));
        $provider = app(AiManager::class)->textProvider('claude-cli');

        $gen = $gateway->generateStreamStep('inv', $provider, 'claude-sonnet-5-5', null, [new UserMessage('Hello')], [], null, null, null, new StepContext);
        foreach ($gen as $e) {
        }
        $result = $gen->getReturn();

        expect($result->continuationToken)->toBe('sess-abc123');
    });

    test('CLI is invoked with --resume when a continuation token is provided', function (): void {
        [$path, $argsFile] = $this->fakeCliCapturingArgs($this->cliSuccessLines('Resumed'));
        $this->useCliBinary($path);

        $gateway = new ClaudeCliGateway(app(Dispatcher::class));
        $provider = app(AiManager::class)->textProvider('claude-cli');

        $generator = $gateway->generateStreamStep(
            invocationId: 'test-inv-id',
            provider: $provider,
            model: 'claude-sonnet-5-5',
            instructions: null,
            messages: [new UserMessage('Follow up')],
            tools: [],
            schema: null,
            options: null,
            timeout: null,
            stepContext: new StepContext(continuationToken: 'sess-previous-123'),
        );

        foreach ($generator as $event) {
            // consume
        }

        $args = trim(file_get_contents($argsFile));

        expect($args)->toContain('--resume')
            ->and($args)->toContain('sess-previous-123');
    });

    test('only the last user message is passed as the prompt when resuming', function (): void {
        [$path, $argsFile] = $this->fakeCliCapturingArgs($this->cliSuccessLines('Response'));
        $this->useCliBinary($path);

        $gateway = new ClaudeCliGateway(app(Dispatcher::class));
        $provider = app(AiManager::class)->textProvider('claude-cli');

        $generator = $gateway->generateStreamStep(
            invocationId: 'test-inv-id',
            provider: $provider,
            model: 'claude-sonnet-5-5',
            instructions: null,
            messages: [
                new UserMessage('First turn'),
                new AssistantMessage('First response'),
                new UserMessage('Second turn'),
            ],
            tools: [],
            schema: null,
            options: null,
            timeout: null,
            stepContext: new StepContext(continuationToken: 'sess-resume'),
        );

        foreach ($generator as $e) {
            // consume
        }

        $args = trim(file_get_contents($argsFile));

        // Only the last user message should appear; earlier turns should not
        expect($args)->toContain('Second turn')
            ->and($args)->not->toContain('First turn');
    });
});
