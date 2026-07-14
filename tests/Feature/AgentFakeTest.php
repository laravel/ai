<?php

use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\QueuedAgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\EmptySchemaStructuredAgent;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\StructuredAgent;

describe('prompt responses', function () {
    test('agents can be faked', function () {
        AssistantAgent::fake([
            'First response',
            fn (string $prompt) => 'Second response ('.$prompt.')',
            new TextResponse('Third response', new Usage, new Meta),
        ]);

        $response = (new AssistantAgent)->prompt('First prompt');
        expect($response->text)->toEqual('First response');

        $response = (new AssistantAgent)->prompt('Second prompt');
        expect($response->text)->toEqual('Second response (Second prompt)');

        $response = (new AssistantAgent)->prompt('Third prompt');
        expect($response->text)->toEqual('Third response');

        // Assertion tests...
        AssistantAgent::assertPrompted('First prompt');
        AssistantAgent::assertNotPrompted('Missing prompt');

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->prompt === 'First prompt';
        });
    });

    test('can assert agent was never prompted', function () {
        AssistantAgent::fake();

        AssistantAgent::assertNeverPrompted();
    });

    test('agents can be faked with no predefined responses', function () {
        AssistantAgent::fake();

        $response = (new AssistantAgent)->prompt('First prompt');
        expect($response->text)->toEqual('Fake response for prompt: First prompt');

        $response = (new AssistantAgent)->prompt('Second prompt');
        expect($response->text)->toEqual('Fake response for prompt: Second prompt');
    });

    test('agents can be faked with a single closure that is invoked for every prompt', function () {
        AssistantAgent::fake(function (string $prompt) {
            return 'Fake response for prompt: '.$prompt;
        });

        $response = (new AssistantAgent)->prompt('First prompt');
        expect($response->text)->toEqual('Fake response for prompt: First prompt');

        $response = (new AssistantAgent)->prompt('Second prompt');
        expect($response->text)->toEqual('Fake response for prompt: Second prompt');
    });

    test('agents can prevent stray prompts', function () {
        AssistantAgent::fake()->preventStrayPrompts();

        $response = (new AssistantAgent)->prompt('First prompt');
    })->throws(RuntimeException::class);

    test('agents with structured output can be faked', function () {
        StructuredAgent::fake([
            ['symbol' => 'Au'],
            fn (string $prompt) => ['symbol' => 'Ag ('.$prompt.')'],
            new StructuredTextResponse(
                ['symbol' => 'Pb'],
                json_encode(['symbol' => 'Pb']),
                new Usage,
                new Meta,
            ),
        ]);

        $response = (new StructuredAgent)->prompt('Gold prompt');
        expect($response['symbol'])->toEqual('Au');

        $response = (new StructuredAgent)->prompt('Silver prompt');
        expect($response['symbol'])->toEqual('Ag (Silver prompt)');

        $response = (new StructuredAgent)->prompt('Lead prompt');
        expect($response['symbol'])->toEqual('Pb');
    });

    test('agents with structured output can be faked with no predefined responses', function () {
        StructuredAgent::fake();

        $response = (new StructuredAgent)->prompt('Gold prompt');

        expect($response['symbol'])->toBeString();
    });

    test('fake closures can throw exceptions', function () {
        AssistantAgent::fake(function () {
            throw new Exception('Something went wrong');
        });

        $response = (new AssistantAgent)->prompt('Test prompt');
    })->throws(Exception::class);

    test('structured agents with empty schemas fall back to a text response', function () {
        EmptySchemaStructuredAgent::fake([
            new TextResponse('Hello', new Usage, new Meta),
        ]);

        $response = (new EmptySchemaStructuredAgent)->prompt('Anything');

        expect($response)->toBeInstanceOf(AgentResponse::class)
            ->and($response)->not->toBeInstanceOf(StructuredAgentResponse::class)
            ->and($response->text)->toEqual('Hello');
    });
});

describe('stream responses', function () {
    test('agent streams can be faked', function () {
        AssistantAgent::fake([
            'First response',
            fn (string $prompt) => 'Second response ('.$prompt.')',
            new TextResponse('Third response', new Usage, new Meta),
        ]);

        $response = (new AssistantAgent)->stream('First prompt');
        $response->each(fn () => true);
        expect($response->text)->toEqual('First response')
            ->and($response->events)->toHaveCount(6);

        $response = (new AssistantAgent)->stream('Second prompt');
        $response->each(fn () => true);
        expect($response->text)->toEqual('Second response (Second prompt)')
            ->and($response->events)->toHaveCount(8);

        $response = (new AssistantAgent)->stream('Third prompt');
        $response->each(fn () => true);
        expect($response->text)->toEqual('Third response')
            ->and($response->events)->toHaveCount(6);
    });

    test('faked stream events share the response invocation id', function () {
        AssistantAgent::fake(['Hello world']);

        $response = (new AssistantAgent)->stream('First prompt');

        $response->each(fn () => true);

        expect($response->events)
            ->each(fn ($event) => $event->invocationId->toBe($response->invocationId));
    });

    test('faked empty response streams without text events', function () {
        AssistantAgent::fake(['']);

        $response = (new AssistantAgent)->stream('First prompt');
        $response->each(fn () => true);

        expect($response->text)->toEqual('')
            ->and($response->events)->toHaveCount(2)
            ->and(collect($response->events)->contains(fn ($event) => $event instanceof TextStart))->toBeFalse();
    });

    test('faked tool calls emit a tool call event while streaming', function () {
        MultiStepToolAgent::fake([
            new ToolCall('call_123', 'FixedNumberGenerator', []),
            'The number is 72019.',
        ]);

        $response = (new MultiStepToolAgent)->stream('Generate a number');
        $response->each(fn () => true);

        $events = collect($response->events);

        $toolCall = $events->first(fn ($event) => $event instanceof ToolCallEvent);

        expect($toolCall)->not->toBeNull()
            ->and($toolCall->toolCall->name)->toBe('FixedNumberGenerator')
            ->and($events->search(fn ($event) => $event instanceof ToolCallEvent))
            ->toBeLessThan($events->search(fn ($event) => $event instanceof ToolResultEvent));
    });
});

describe('queue responses', function () {
    test('queued agents can be faked', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->queue('First prompt');

        AssistantAgent::assertQueued('First prompt');
        AssistantAgent::assertNotQueued('Second prompt');

        AssistantAgent::assertQueued(function (QueuedAgentPrompt $prompt) {
            return $prompt->prompt === 'First prompt';
        });

        AssistantAgent::assertNotQueued(function (QueuedAgentPrompt $prompt) {
            return $prompt->prompt === 'Second prompt';
        });
    });

    test('can assert agent was never queued', function () {
        AssistantAgent::fake();

        AssistantAgent::assertNeverQueued();
    });

    test('assert queued does not throw undefined key when agent was never queued', function () {
        AssistantAgent::fake();

        // Should fail the assertion gracefully, not throw an undefined array key error.
        try {
            AssistantAgent::assertQueued('Some prompt');
            test()->fail('Expected assertion to fail.');
        } catch (AssertionFailedError $e) {
            expect($e->getMessage())->toContain('An expected queued prompt was not received.');
        }
    });

    test('assert not queued does not throw undefined key when agent was never queued', function () {
        AssistantAgent::fake();

        // Should pass gracefully since the agent was never queued.
        AssistantAgent::assertNotQueued('Some prompt');
    });
});

describe('provider enum support', function () {
    test('queued agents accept ai provider enum', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->queue('Enum prompt', provider: Lab::OpenAI);

        AssistantAgent::assertQueued(function (QueuedAgentPrompt $prompt) {
            return $prompt->prompt === 'Enum prompt'
                && $prompt->provider === Lab::OpenAI;
        });
    });

    test('prompt accepts ai provider enum', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Enum prompt', provider: Lab::Anthropic);

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->prompt === 'Enum prompt';
        });
    });

    test('stream accepts ai provider enum', function () {
        AssistantAgent::fake();

        $response = (new AssistantAgent)->stream('Enum stream', provider: Lab::Gemini);
        $response->each(fn () => true);

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->prompt === 'Enum stream';
        });
    });
});

describe('timeout handling', function () {
    test('timeout can be passed to agent prompt', function () {
        AssistantAgent::fake();

        $timeout = 120;

        (new AssistantAgent)->prompt('Test prompt', timeout: $timeout);

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->prompt === 'Test prompt'
                && $prompt->timeout === 120;
        });
    });

    test('timeout defaults to sdk default when not provided', function () {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Test prompt');

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->prompt === 'Test prompt'
                && $prompt->timeout === 60;
        });
    });

    test('timeout can be passed to agent stream', function () {
        AssistantAgent::fake();

        $timeout = 120;

        (new AssistantAgent)->stream('Test prompt', timeout: $timeout);

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->prompt === 'Test prompt'
                && $prompt->timeout === 120;
        });
    });

    test('timeout is preserved when revising agent prompt', function () {
        AssistantAgent::fake();

        $prompt = new AgentPrompt(
            new AssistantAgent,
            'Original prompt',
            [],
            Ai::textProviderFor(new AssistantAgent, 'groq'),
            'test-model',
            150
        );

        $revised = $prompt->revise('Revised prompt');

        expect($revised->timeout)->toEqual(150)
            ->and($revised->prompt)->toEqual('Revised prompt');
    });
});
