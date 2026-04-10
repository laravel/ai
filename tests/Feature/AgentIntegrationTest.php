<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ConversationalAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\Feature\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

uses()->group('integration');

beforeEach(function () {
    $this->provider = 'groq';
    $this->model = 'openai/gpt-oss-20b';
    $this->toolProvider = 'anthropic';
    $this->toolModel = 'claude-haiku-4-5-20251001';
});

test('agents can get a simple text response', function () {
    Event::fake();

    $agent = new AssistantAgent;

    $response = $agent->prompt(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $this->provider,
        model: $this->model,
    );

    expect(str_contains($response->text, 'Laravel'))->toBeTrue()
        ->and(Str::isUuid($response->invocationId, 7))->toBeTrue()
        ->and($response->messages->count())->toBeGreaterThan(0)
        ->and($response->meta->provider)->toEqual('groq')
        ->and($response->meta->model)->toEqual('openai/gpt-oss-20b')
        ->and($response->steps->count())->toBeGreaterThan(0);

    Event::assertDispatched(PromptingAgent::class);
    Event::assertDispatched(AgentPrompted::class);
});

test('ad hoc agents can be prompted', function () {
    $response = agent()->prompt(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $this->provider,
        model: $this->model,
    );

    expect(str_contains($response->text, 'Laravel'))->toBeTrue();
});

test('agents can stream a response', function () {
    Event::fake();

    $agent = new AssistantAgent;

    $response = $agent->stream(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $this->provider,
        model: $this->model,
    )->then(function (StreamedAgentResponse $response) {
        $_SERVER['__testing.response'] = $response;
    })->then(function () {
        $_SERVER['__testing.invoked'] = true;
    });

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect((new Collection($events))
        ->whereInstanceOf(TextDelta::class)
        ->isNotEmpty())->toBeTrue()
        ->and(str_contains($response->text, 'Laravel'))->toBeTrue()
        ->and($_SERVER['__testing.response']->events)->toHaveSameSize($events)
        ->and($_SERVER['__testing.invoked'])->toBeTrue();

    Event::assertDispatched(StreamingAgent::class);
    Event::assertDispatched(AgentStreamed::class);

    unset($_SERVER['__testing.response']);
    unset($_SERVER['__testing.invoked']);
});

test('agents can queue a response', function () {
    $agent = new AssistantAgent;

    $agent->queue(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $this->provider,
        model: $this->model,
    )->then(function (AgentResponse $response) {
        $_ENV['__testing.response'] = $response;
    });

    $response = $_ENV['__testing.response'];

    expect(str_contains($response->text, 'Laravel'))->toBeTrue();

    unset($_SERVER['__testing.response']);
});

test('ad hoc agents can queue a response', function () {
    agent()->queue(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $this->provider,
        model: $this->model,
    )->then(function (AgentResponse $response) {
        $_ENV['__testing.response'] = $response;
    });

    $response = $_ENV['__testing.response'];

    expect(str_contains($response->text, 'Laravel'))->toBeTrue();

    unset($_SERVER['__testing.response']);
});

test('ad hoc structured agents can queue a response', function () {
    agent(
        schema: fn ($schema) => [
            'symbol' => $schema->string()->required(),
        ]
    )->queue(
        'What is the chemical symbol for silver?',
        provider: $this->provider,
        model: $this->model,
    )->then(function (AgentResponse $response) {
        $_ENV['__testing.response'] = $response;
    });

    $response = $_ENV['__testing.response'];

    expect(strtolower($response['symbol']))->toEqual('ag');

    unset($_SERVER['__testing.response']);
});

test('agents can have conversation state', function () {
    $agent = new ConversationalAgent;

    $response = $agent->prompt(
        'What did I say my name was?',
        provider: $this->provider,
        model: $this->model,
    );

    expect(str_contains($response->text, 'Taylor'))->toBeTrue();
});

test('agents can have structured output', function () {
    $agent = new StructuredAgent;

    $response = $agent->prompt(
        'What is the chemical symbol for silver?',
        provider: $this->provider,
        model: $this->model,
    );

    expect(strtolower($response['symbol']))->toEqual('ag')
        ->and($response->steps->count())->toBeGreaterThan(0);
});

test('ad hoc agents can have structured output', function () {
    $response = agent(
        schema: fn (JsonSchema $schema) => [
            'symbol' => $schema->string()->required(),
        ],
    )->prompt(
        'What is the chemical symbol for silver?',
        provider: $this->provider,
        model: $this->model,
    );

    expect(strtolower($response['symbol']))->toEqual('ag');
});

test('agents can use tools', function () {
    Event::fake();

    // Verify with a random number...
    $agent = new ToolUsingAgent;

    $response = $agent->prompt(
        'Can I have a random number between 1 and 1000?',
        provider: $this->toolProvider,
        model: $this->toolModel,
    );

    expect($response['number'])->toBeBetween(1, 1000)
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolResults)->toHaveCount(1);

    Event::assertDispatched(InvokingTool::class);

    Event::assertDispatched(ToolInvoked::class, function ($event) {
        return ! is_null($event->toolInvocationId);
    });

    // Verify with a fixed response...
    $agent = new ToolUsingAgent(fixed: true);

    $response = $agent->prompt(
        'Can I have a random number?',
        provider: $this->toolProvider,
        model: $this->toolModel,
    );

    expect($response['number'])->toBe(72019);
});

test('agent tool exception handling is not magical', function () {
    Event::fake();

    $agent = new ToolUsingAgent(toolThrowsException: true);

    $caught = false;

    try {
        $response = $agent->prompt(
            'Can I have a random number between 1 and 1000?',
            provider: $this->toolProvider,
            model: $this->toolModel,
        );

        $text = $response->text;
    } catch (Exception $e) {
        $caught = true;

        expect($e)->toBeInstanceOf(Exception::class)
            ->and($e->getMessage())->toEqual('Forced to throw exception.');
    }

    expect($caught)->toBeTrue();
});
