<?php

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Jobs\InvokeAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\QueuedAgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\Citation as CitationEvent;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\ConversationalAgent;
use Tests\Fixtures\Agents\EmptySchemaStructuredAgent;
use Tests\Fixtures\Agents\MultiStepToolAgent;
use Tests\Fixtures\Agents\StructuredAgent;

describe('prompt responses', function (): void {
    test('agents can be faked', function (): void {
        AssistantAgent::fake([
            'First response',
            fn (string $prompt): string => 'Second response ('.$prompt.')',
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
        AssistantAgent::assertPromptedTimes(3);
        AssistantAgent::assertNotPrompted('Missing prompt');

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'First prompt');
    });

    test('can assert agent was never prompted', function (): void {
        AssistantAgent::fake();

        AssistantAgent::assertNeverPrompted();
    });

    test('fake responses may expose a raw http response', function (): void {
        AssistantAgent::fake([
            (new TextResponse('Hello', new Usage, new Meta))->withRawResponse(new Response(
                new Psr7Response(200, ['x-ratelimit-remaining-requests' => '99'], '{}')
            )),
        ]);

        $response = (new AssistantAgent)->prompt('Hi');

        expect($response->raw)->toBeInstanceOf(Response::class)
            ->and($response->raw->header('x-ratelimit-remaining-requests'))->toBe('99');
    });

    test('agents can be faked with no predefined responses', function (): void {
        AssistantAgent::fake();

        $response = (new AssistantAgent)->prompt('First prompt');
        expect($response->text)->toEqual('Fake response for prompt: First prompt');

        $response = (new AssistantAgent)->prompt('Second prompt');
        expect($response->text)->toEqual('Fake response for prompt: Second prompt');
    });

    test('agents can be faked with a single closure that is invoked for every prompt', function (): void {
        AssistantAgent::fake(fn (string $prompt): string => 'Fake response for prompt: '.$prompt);

        $response = (new AssistantAgent)->prompt('First prompt');
        expect($response->text)->toEqual('Fake response for prompt: First prompt');

        $response = (new AssistantAgent)->prompt('Second prompt');
        expect($response->text)->toEqual('Fake response for prompt: Second prompt');
    });

    test('agents can prevent stray prompts', function (): void {
        AssistantAgent::fake()->preventStrayPrompts();

        $response = (new AssistantAgent)->prompt('First prompt');
    })->throws(RuntimeException::class);

    test('agents with structured output can be faked', function (): void {
        StructuredAgent::fake([
            ['symbol' => 'Au'],
            fn (string $prompt): array => ['symbol' => 'Ag ('.$prompt.')'],
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

    test('agents with structured output can be faked with no predefined responses', function (): void {
        StructuredAgent::fake();

        $response = (new StructuredAgent)->prompt('Gold prompt');

        expect($response['symbol'])->toBeString();
    });

    test('fake closures can throw exceptions', function (): void {
        AssistantAgent::fake(function (): void {
            throw new Exception('Something went wrong');
        });

        $response = (new AssistantAgent)->prompt('Test prompt');
    })->throws(Exception::class);

    test('structured agents with empty schemas fall back to a text response', function (): void {
        EmptySchemaStructuredAgent::fake([
            new TextResponse('Hello', new Usage, new Meta),
        ]);

        $response = (new EmptySchemaStructuredAgent)->prompt('Anything');

        expect($response)->toBeInstanceOf(AgentResponse::class)
            ->and($response)->not->toBeInstanceOf(StructuredAgentResponse::class)
            ->and($response->text)->toEqual('Hello');
    });

    test('agents can fake paused approval responses and assert resume prompts', function () {
        ConversationalAgent::fake([
            AgentResponse::fakeWithPendingApprovals([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'config/app.php'], 'Deletes a file'),
            ]),
            'Resumed',
        ]);

        $response = (new ConversationalAgent)->prompt('Delete config/app.php');

        expect($response->hasPendingApprovals())->toBeTrue()
            ->and($response->pendingApprovals)->toHaveCount(1);

        (new ConversationalAgent)->prompt(Decisions::from(['call-1' => true]));

        ConversationalAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->approvalDecisions?->get('call-1')?->isApproved() === true;
        });
    });
});

describe('stream responses', function (): void {
    test('agent streams can be faked', function (): void {
        AssistantAgent::fake([
            'First response',
            fn (string $prompt): string => 'Second response ('.$prompt.')',
            new TextResponse('Third response', new Usage, new Meta),
        ]);

        $response = (new AssistantAgent)->stream('First prompt');
        $response->each(fn (): true => true);
        expect($response->text)->toEqual('First response')
            ->and($response->events)->toHaveCount(6);

        $response = (new AssistantAgent)->stream('Second prompt');
        $response->each(fn (): true => true);
        expect($response->text)->toEqual('Second response (Second prompt)')
            ->and($response->events)->toHaveCount(8);

        $response = (new AssistantAgent)->stream('Third prompt');
        $response->each(fn (): true => true);
        expect($response->text)->toEqual('Third response')
            ->and($response->events)->toHaveCount(6);
    });

    test('faked agents can stream the sources an answer cited', function (): void {
        AssistantAgent::fake([
            new TextResponse('Laravel MCP ships an MCP server.', new Usage, new Meta('anthropic', 'test-model', collect([
                new UrlCitation('https://laravel.com/docs/mcp', 'Laravel MCP'),
            ]))),
        ]);

        $response = (new AssistantAgent)->stream('What does Laravel MCP do?');
        $response->each(fn (): true => true);

        expect($response->events->whereInstanceOf(CitationEvent::class))->toHaveCount(1)
            ->and($response->citations->pluck('url')->all())->toBe(['https://laravel.com/docs/mcp']);
    });

    test('a faked stream reports no sources when the answer cited nothing', function (): void {
        AssistantAgent::fake(['It is 12°C.']);

        $response = (new AssistantAgent)->stream('How cold is it?');
        $response->each(fn (): true => true);

        expect($response->events->whereInstanceOf(CitationEvent::class))->toBeEmpty()
            ->and($response->citations)->toBeEmpty();
    });

    test('faked stream events share the response invocation id', function (): void {
        AssistantAgent::fake(['Hello world']);

        $response = (new AssistantAgent)->stream('First prompt');

        $response->each(fn (): true => true);

        expect($response->events)
            ->each(fn ($event) => $event->invocationId->toBe($response->invocationId));
    });

    test('faked empty response streams without text events', function (): void {
        AssistantAgent::fake(['']);

        $response = (new AssistantAgent)->stream('First prompt');
        $response->each(fn (): true => true);

        expect($response->text)->toEqual('')
            ->and($response->events)->toHaveCount(2)
            ->and(collect($response->events)->contains(fn ($event): bool => $event instanceof TextStart))->toBeFalse();
    });

    test('faked tool calls emit a tool call event while streaming', function (): void {
        MultiStepToolAgent::fake([
            new ToolCall('call_123', 'FixedNumberGenerator', []),
            'The number is 72019.',
        ]);

        $response = (new MultiStepToolAgent)->stream('Generate a number');
        $response->each(fn (): true => true);

        $events = collect($response->events);

        $toolCall = $events->first(fn ($event): bool => $event instanceof ToolCallEvent);

        expect($toolCall)->not->toBeNull()
            ->and($toolCall->toolCall->name)->toBe('FixedNumberGenerator')
            ->and($events->search(fn ($event): bool => $event instanceof ToolCallEvent))
            ->toBeLessThan($events->search(fn ($event): bool => $event instanceof ToolResultEvent));
    });
});

describe('queue responses', function (): void {
    test('queued agents can be faked', function (): void {
        AssistantAgent::fake();

        (new AssistantAgent)->queue('First prompt');

        AssistantAgent::assertQueued('First prompt');
        AssistantAgent::assertNotQueued('Second prompt');

        AssistantAgent::assertQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'First prompt');

        AssistantAgent::assertNotQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'Second prompt');
    });

    test('queued agents can be faked and then callback is executed', function (): void {
        AssistantAgent::fake(['First response']);

        $GLOBALS['agentResponse'] = null;

        (new AssistantAgent)->queue('First prompt')->then(function ($response): void {
            $GLOBALS['agentResponse'] = $response;
        });

        AssistantAgent::assertQueued('First prompt');

        expect($GLOBALS['agentResponse'])->toBeInstanceOf(AgentResponse::class);
        expect($GLOBALS['agentResponse']->text)->toEqual('First response');
    });

    test('queued agents can be faked and then callback is not executed if queue is faked', function (): void {
        AssistantAgent::fake(['First response']);
        Queue::fake();

        $GLOBALS['agentResponse'] = null;

        (new AssistantAgent)->queue('First prompt')->then(function ($response): void {
            $GLOBALS['agentResponse'] = $response;
        });

        AssistantAgent::assertQueued('First prompt');

        expect($GLOBALS['agentResponse'])->toBeNull();

        Queue::assertPushed(InvokeAgent::class);
    });

    test('can assert agent was never queued', function (): void {
        AssistantAgent::fake();

        AssistantAgent::assertNeverQueued();
    });

    test('assert queued does not throw undefined key when agent was never queued', function (): void {
        AssistantAgent::fake();

        // Should fail the assertion gracefully, not throw an undefined array key error.
        try {
            AssistantAgent::assertQueued('Some prompt');
            test()->fail('Expected assertion to fail.');
        } catch (AssertionFailedError $assertionFailedError) {
            expect($assertionFailedError->getMessage())->toContain('An expected queued prompt was not received.');
        }
    });

    test('assert not queued does not throw undefined key when agent was never queued', function (): void {
        AssistantAgent::fake();

        // Should pass gracefully since the agent was never queued.
        AssistantAgent::assertNotQueued('Some prompt');
    });
});

describe('provider enum support', function (): void {
    test('queued agents accept ai provider enum', function (): void {
        AssistantAgent::fake();

        (new AssistantAgent)->queue('Enum prompt', provider: Lab::OpenAI);

        AssistantAgent::assertQueued(fn (QueuedAgentPrompt $prompt): bool => $prompt->prompt === 'Enum prompt'
            && $prompt->provider === Lab::OpenAI);
    });

    test('prompt accepts ai provider enum', function (): void {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Enum prompt', provider: Lab::Anthropic);

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Enum prompt');
    });

    test('stream accepts ai provider enum', function (): void {
        AssistantAgent::fake();

        $response = (new AssistantAgent)->stream('Enum stream', provider: Lab::Gemini);
        $response->each(fn (): true => true);

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Enum stream');
    });
});

describe('timeout handling', function (): void {
    test('timeout can be passed to agent prompt', function (): void {
        AssistantAgent::fake();

        $timeout = 120;

        (new AssistantAgent)->prompt('Test prompt', timeout: $timeout);

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Test prompt'
            && $prompt->timeout === 120);
    });

    test('timeout defaults to sdk default when not provided', function (): void {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Test prompt');

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Test prompt'
            && $prompt->timeout === 60);
    });

    test('timeout can be passed to agent stream', function (): void {
        AssistantAgent::fake();

        $timeout = 120;

        (new AssistantAgent)->stream('Test prompt', timeout: $timeout);

        AssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Test prompt'
            && $prompt->timeout === 120);
    });

    test('timeout is preserved when revising agent prompt', function (): void {
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

    test('revising a resume prompt is a no-op since it carries no prompt text', function () {
        $prompt = new AgentPrompt(
            new AssistantAgent,
            '',
            [],
            Ai::textProviderFor(new AssistantAgent, 'groq'),
            'test-model',
            approvalDecisions: Decisions::from(['call-1' => Decision::approve()]),
        );

        $revised = $prompt->append('extra context');

        expect($revised)->toBe($prompt)
            ->and($revised->prompt)->toBe('')
            ->and($revised->approvalDecisions)->toBe($prompt->approvalDecisions);
    });
});
