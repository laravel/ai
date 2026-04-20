<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Files;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ConversationalAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

use function Laravel\Ai\agent;

test('agents can get a simple text response', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    Event::fake();

    $agent = new AssistantAgent;

    $response = $agent->prompt(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $provider,
        model: $model,
    );

    expect(str_contains($response->text, 'Laravel'))->toBeTrue()
        ->and(Str::isUuid($response->invocationId, 7))->toBeTrue()
        ->and($response->messages->count())->toBeGreaterThan(0)
        ->and($response->meta->provider)->toEqual($provider)
        ->and($response->meta->model)->toBeString()->not->toBeEmpty()
        ->and($response->steps->count())->toBeGreaterThan(0);

    Event::assertDispatched(PromptingAgent::class);
    Event::assertDispatched(AgentPrompted::class);
})->with('agent-providers');

test('ad hoc agents can be prompted', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $response = agent()->prompt(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $provider,
        model: $model,
    );

    expect(str_contains($response->text, 'Laravel'))->toBeTrue();
})->with('agent-providers');

test('agents can stream a response', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    Event::fake();

    $agent = new AssistantAgent;

    $response = $agent->stream(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $provider,
        model: $model,
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
})->with('agent-providers');

test('agents can queue a response', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $agent = new AssistantAgent;

    $agent->queue(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $provider,
        model: $model,
    )->then(function (AgentResponse $response) {
        $_ENV['__testing.response'] = $response;
    });

    $response = $_ENV['__testing.response'];

    expect(str_contains($response->text, 'Laravel'))->toBeTrue();

    unset($_SERVER['__testing.response']);
})->with('agent-providers');

test('ad hoc agents can queue a response', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    agent()->queue(
        'What is the name of the PHP framework created by Taylor Otwell?',
        provider: $provider,
        model: $model,
    )->then(function (AgentResponse $response) {
        $_ENV['__testing.response'] = $response;
    });

    $response = $_ENV['__testing.response'];

    expect(str_contains($response->text, 'Laravel'))->toBeTrue();

    unset($_SERVER['__testing.response']);
})->with('agent-providers');

test('ad hoc structured agents can queue a response', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    agent(
        schema: fn ($schema) => [
            'symbol' => $schema->string()->required(),
        ]
    )->queue(
        'What is the chemical symbol for silver?',
        provider: $provider,
        model: $model,
    )->then(function (AgentResponse $response) {
        $_ENV['__testing.response'] = $response;
    });

    $response = $_ENV['__testing.response'];

    expect(strtolower($response['symbol']))->toEqual('ag');

    unset($_SERVER['__testing.response']);
})->with('agent-providers');

test('agents can have conversation state', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $agent = new ConversationalAgent;

    $response = $agent->prompt(
        'What did I say my name was?',
        provider: $provider,
        model: $model,
    );

    expect(str_contains($response->text, 'Taylor'))->toBeTrue();
})->with('agent-providers');

test('agents can have structured output', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $agent = new StructuredAgent;

    $response = $agent->prompt(
        'What is the chemical symbol for silver?',
        provider: $provider,
        model: $model,
    );

    expect(strtolower($response['symbol']))->toEqual('ag')
        ->and($response->steps->count())->toBeGreaterThan(0);
})->with('agent-providers');

test('ad hoc agents can have structured output', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    $response = agent(
        schema: fn (JsonSchema $schema) => [
            'symbol' => $schema->string()->required(),
        ],
    )->prompt(
        'What is the chemical symbol for silver?',
        provider: $provider,
        model: $model,
    );

    expect(strtolower($response['symbol']))->toEqual('ag');
})->with('agent-providers');

test('agents can use tools', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    Event::fake();

    // Verify with a random number...
    $agent = new ToolUsingAgent;

    $response = $agent->prompt(
        'Can I have a random number between 1 and 1000?',
        provider: $provider,
        model: $model,
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
        provider: $provider,
        model: $model,
    );

    expect($response['number'])->toBe(72019);
})->with('agent-providers');

test('agents can analyze text document attachments', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    Storage::fake('docs');

    Storage::disk('docs')->put('document-one.txt', 'The capital of France is Paris.');
    Storage::disk('docs')->put('document-two.txt', 'The capital of Japan is Tokyo.');
    Storage::disk('docs')->put('document-three.txt', 'The capital of Australia is Canberra.');

    $record = ['name' => 'Taylor', 'role' => 'creator of Laravel'];

    $response = agent('Answer using only the attached documents.')->prompt(
        'List the three capital cities mentioned across the attached documents and name the person in the JSON record.',
        [
            Files\Document::fromStorage('document-one.txt', 'docs'),
            Files\Document::fromStorage('document-two.txt', 'docs'),
            Files\Document::fromStorage('document-three.txt', 'docs'),
            Files\Document::fromString(json_encode($record), 'text/plain'),
        ],
        provider: $provider,
        model: $model,
    );

    expect($response->text)->toContain('Paris')
        ->and($response->text)->toContain('Tokyo')
        ->and($response->text)->toContain('Canberra')
        ->and($response->text)->toContain('Taylor');
})->with('agent-document-providers');

test('agent tool exception handling is not magical', function (string $provider, string $apiKey, string $model) {
    requiresApiKey($apiKey);

    Event::fake();

    $agent = new ToolUsingAgent(toolThrowsException: true);

    $caught = false;

    try {
        $response = $agent->prompt(
            'Can I have a random number between 1 and 1000?',
            provider: $provider,
            model: $model,
        );

        $text = $response->text;
    } catch (Exception $e) {
        $caught = true;

        expect($e)->toBeInstanceOf(Exception::class)
            ->and($e->getMessage())->toEqual('Forced to throw exception.');
    }

    expect($caught)->toBeTrue();
})->with('agent-providers');
