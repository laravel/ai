<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\ToolChoice;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\CapturingStepGateway;
use Tests\Fixtures\FakeConversationStore;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\NamedTool;

test('agent middleware wraps every generation step', function (): void {
    AssistantAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Fake response',
    ]);

    $seen = [];

    $response = (new AssistantAgent)
        ->withTools([new FixedNumberGenerator])
        ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
            $seen[] = [$step->number, count($step->messages), count($step->steps), Arr::last($step->steps)?->toolCalls[0]->id];

            return $next($step);
        }])
        ->prompt('Test prompt');

    expect($response->text)->toEqual('Fake response')
        ->and($seen)->toBe([[0, 1, 0, null], [1, 3, 1, 'call_1']]);
});

test('agent middleware wraps every generation step when streaming', function (): void {
    AssistantAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Fake response',
    ]);

    $seen = [];

    $response = (new AssistantAgent)
        ->withTools([new FixedNumberGenerator])
        ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
            $seen[] = $step->number;

            return $next($step);
        }])
        ->stream('Test prompt');

    $text = null;

    $response
        ->each(fn (): true => true)
        ->then(function (StreamedAgentResponse $response) use (&$text): void {
            $text = $response->text;
        });

    expect($text)->toEqual('Fake response')
        ->and($seen)->toBe([0, 1]);
});

test('agent middleware sees the provider, accumulated usage and final step flag', function (): void {
    $gateway = new CapturingStepGateway;
    $seen = [];

    (new TextGenerationLoop($gateway))->generate(
        Ai::textProviderFor(new AssistantAgent, 'openai'),
        'gpt-test',
        'Be helpful.',
        [new UserMessage('Hi')],
        [new FixedNumberGenerator],
        options: new TextGenerationOptions(maxSteps: 2, agent: (new AssistantAgent)->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
            $seen[] = [$step->provider, $step->isFinalStep, $step->usage->promptTokens, $step->usage->completionTokens];

            return $next($step);
        }])),
    );

    expect($seen)->toBe([['openai', false, 0, 0], ['openai', true, 10, 5]]);
});

test('agent middleware then callback receives the step response before its tools run', function (): void {
    AssistantAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Fake response',
    ]);

    $seen = [];

    Event::listen(InvokingTool::class, function () use (&$seen): void {
        $seen[] = 'tool';
    });

    (new AssistantAgent)
        ->withTools([new FixedNumberGenerator])
        ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
            return $next($step)->then(function (StepResponse $response) use (&$seen): void {
                $seen[] = $response->finishReason->value;
            });
        }])
        ->prompt('Test prompt');

    expect($seen)->toBe(['tool_calls', 'tool', 'stop']);
});

test('an outer middleware receives a step result when an inner middleware short-circuits', function (): void {
    AssistantAgent::fake(['Fake response']);

    $seen = [];

    $observer = function (PendingStep $step, Closure $next) use (&$seen) {
        return $next($step)->then(function (StepResponse $response) use (&$seen): void {
            $seen[] = $response->text;
        });
    };

    $response = (new AssistantAgent)
        ->withMiddleware([$observer, shortCircuitingMiddleware()])
        ->prompt('Test prompt');

    expect($response->text)->toBe('Short-circuited response')
        ->and($seen)->toBe(['Short-circuited response']);

    $streamed = (new AssistantAgent)
        ->withMiddleware([$observer, shortCircuitingMiddleware()])
        ->stream('Test prompt');

    iterator_to_array($streamed, false);

    expect($streamed->text)->toBe('Short-circuited response')
        ->and($seen)->toBe(['Short-circuited response', 'Short-circuited response']);
});

test('a failed step reports the model the middleware chose', function (): void {
    Event::fake([StepFailed::class]);

    AssistantAgent::fake([fn () => throw new RuntimeException('Provider down.')]);

    expect(fn (): mixed => (new AssistantAgent)
        ->withMiddleware([fn (PendingStep $step, Closure $next) => $next($step->withModel('other-model'))])
        ->prompt('Test prompt'))->toThrow(RuntimeException::class, 'Provider down.');

    Event::assertDispatched(StepFailed::class, fn (StepFailed $event): bool => $event->model === 'other-model');
});

test('agent middleware then callback runs once a streamed step has drained', function (): void {
    AssistantAgent::fake(['Fake response']);

    $seen = [];

    $response = (new AssistantAgent)
        ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
            return $next($step)->then(function (StepResponse $response) use (&$seen): void {
                $seen[] = $response->text;
            });
        }])
        ->stream('Test prompt');

    expect($seen)->toBe([]);

    foreach ($response as $event) {
        //
    }

    expect($seen)->toBe(['Fake response']);
});

test('agent middleware may short-circuit a step', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent)
        ->withMiddleware([shortCircuitingMiddleware()])
        ->prompt('Test prompt');

    expect($response->text)->toBe('Short-circuited response')
        ->and($response->steps)->toHaveCount(1);
});

test('agent middleware that reads the response of a streamed step still yields its events', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent)
        ->withMiddleware([function (PendingStep $step, Closure $next) {
            $result = $next($step);

            return $result->response()->text === '' ? new StepResponse('Fallback', [], FinishReason::Stop, new Usage, new Meta) : $result;
        }])
        ->stream('Test prompt');

    $events = iterator_to_array($response, false);

    expect(array_filter($events, fn (object $event): bool => $event instanceof TextDelta))->not->toBeEmpty()
        ->and($response->text)->toBe('Fake response');
});

test('agent middleware that iterates a streamed step itself still yields its events', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent)
        ->withMiddleware([function (PendingStep $step, Closure $next) {
            $result = $next($step);

            foreach ($result as $event) {
                //
            }

            return $result;
        }])
        ->stream('Test prompt');

    iterator_to_array($response, false);

    expect($response->text)->toBe('Fake response');
});

test('agent middleware that replaces a streamed step response narrates the replacement', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent)
        ->withMiddleware([function (PendingStep $step, Closure $next) {
            $result = $next($step);

            return $result->response()->text === 'Fake response' ? new StepResponse('Replaced', [], FinishReason::Stop, new Usage, new Meta) : $result;
        }])
        ->stream('Test prompt');

    $deltas = array_values(array_filter(iterator_to_array($response, false), fn (object $event): bool => $event instanceof TextDelta));

    expect(array_map(fn (TextDelta $event): string => $event->delta, $deltas))->toBe(['Replaced']);
});

test('step provider options override the agent\'s own and never carry headers', function (): void {
    Event::fake([StartingStep::class]);

    $agent = new class extends AssistantAgent implements HasProviderOptions
    {
        public function providerOptions(Lab|string $provider): array
        {
            return ['reasoning' => 'high', 'store' => false, 'ai_sdk_extra_headers' => ['X-Test' => '1']];
        }
    };

    $agent::fake(['Fake response']);

    $agent
        ->withMiddleware([fn (PendingStep $step, Closure $next) => $next($step->withProviderOptions(['reasoning' => 'low', 'ai_sdk_extra_headers' => ['X-Test' => '2']]))])
        ->prompt('Test prompt');

    Event::assertDispatched(StartingStep::class, fn (StartingStep $event): bool => $event->options->providerOptions('openai') === ['reasoning' => 'low', 'store' => false]);
});

test('agent middleware may short-circuit a streamed step', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent)
        ->withMiddleware([shortCircuitingMiddleware()])
        ->stream('Test prompt');

    $events = iterator_to_array($response, false);

    expect(array_map(fn (object $event): string => $event::class, $events))->toBe([StreamStart::class, TextStart::class, TextDelta::class, TextEnd::class, StreamEnd::class])
        ->and($response->text)->toBe('Short-circuited response');
});

test('agent middleware may change the model and options for a step', function (): void {
    Event::fake([StartingStep::class, StepCompleted::class]);

    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent)
        ->withMiddleware([fn (PendingStep $step, Closure $next) => $next(
            $step->withModel('other-model')->withMaxTokens(1000)->withToolChoice('none')->withProviderOptions(['reasoning' => 'low']),
        )])
        ->prompt('Test prompt');

    expect($response->meta->model)->toBe('other-model');

    Event::assertDispatched(StartingStep::class, fn (StartingStep $event): bool => $event->model === 'other-model'
        && $event->options->maxTokens === 1000
        && $event->options->toolChoice->mode === ToolChoice::none
        && $event->options->providerOptions('openai') === ['reasoning' => 'low']);

    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->model === 'other-model');
});

test('a streamed response reports the model the middleware chose', function (): void {
    Event::fake([StepCompleted::class]);

    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent)
        ->withMiddleware([fn (PendingStep $step, Closure $next) => $next($step->withModel('other-model'))])
        ->stream('Test prompt');

    $response
        ->each(fn (): true => true)
        ->then(function (StreamedAgentResponse $response): void {
            expect($response->meta->model)->toBe('other-model');
        });

    Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->model === 'other-model');
});

test('agent middleware that replaces the history or the model drops the provider continuation token', function (): void {
    $gateway = new CapturingStepGateway;

    $run = function (Closure $middleware) use ($gateway): array {
        runThroughGateway($gateway, [$middleware]);

        return array_map(fn (array $call): ?string => $call['context']->continuationToken, $gateway->calls);
    };

    expect($run(fn (PendingStep $step, Closure $next) => $next($step)))->toBe([null, 'resp_1'])
        ->and($run(fn (PendingStep $step, Closure $next) => $next($step->withMessages(array_slice($step->messages, -1)))))->toBe([null, null])
        ->and($run(fn (PendingStep $step, Closure $next) => $next($step->isFirstStep() ? $step : $step->withModel('cheaper-model'))))->toBe([null, null])
        ->and($run(fn (PendingStep $step, Closure $next) => $next($step->isFirstStep() ? $step->withModel('cheaper-model') : $step)))->toBe([null, null])
        ->and($run(fn (PendingStep $step, Closure $next) => $next($step->withModel('cheaper-model'))))->toBe([null, 'resp_1'])
        ->and($run(fn (PendingStep $step, Closure $next) => $next($step->isFirstStep() ? $step : $step->withInstructions('Wrap up.'))))->toBe([null, null]);
});

test('agent middleware may narrow the tools and instructions for a step', function (): void {
    $gateway = new CapturingStepGateway;

    runThroughGateway($gateway, [
        fn (PendingStep $step, Closure $next) => $next($step->isFirstStep()
            ? $step->withoutTools('custom_named_tool', 'WebSearch')->withInstructions('Plan first.')
            : $step->onlyTools('custom_named_tool')),
    ], tools: [new FixedNumberGenerator, new NamedTool, new WebSearch]);

    expect($gateway->calls[0]['tools'])->toHaveCount(1)
        ->and($gateway->calls[0]['tools'][0])->toBeInstanceOf(FixedNumberGenerator::class)
        ->and($gateway->calls[0]['instructions'])->toBe('Plan first.')
        ->and($gateway->calls[1]['tools'])->toHaveCount(1)
        ->and($gateway->calls[1]['tools'][0])->toBeInstanceOf(NamedTool::class)
        ->and($gateway->calls[1]['instructions'])->toBe('Be helpful.');
});

test('agent middleware must return the next step result or a step response', function (): void {
    AssistantAgent::fake(['Fake response']);

    (new AssistantAgent)
        ->withMiddleware([fn (PendingStep $step, Closure $next) => 'nope'])
        ->prompt('Test prompt');
})->throws(LogicException::class, 'Agent middleware must return the next step result or a StepResponse.');

test('agent middleware that fails before the model is called fails the run without a step failure', function (): void {
    Event::fake([StepFailed::class, AgentFailed::class]);

    AssistantAgent::fake(['Fake response']);

    expect(fn (): mixed => (new AssistantAgent)
        ->withMiddleware([fn (PendingStep $step, Closure $next) => throw new RuntimeException('Blocked by middleware.')])
        ->prompt('Test prompt'))->toThrow(RuntimeException::class, 'Blocked by middleware.');

    Event::assertNotDispatched(StepFailed::class);
    Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->exception->getMessage() === 'Blocked by middleware.');
});

test('stream response conversation id is available after remembered conversations stream completes', function (): void {
    app()->instance(ConversationStore::class, new FakeConversationStore);

    RememberingAssistantAgent::fake([
        'Fake response',
    ]);

    $user = new class
    {
        public int $id = 1;
    };

    $agent = (new RememberingAssistantAgent)->forUser($user);

    $response = $agent->stream('Test prompt');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->conversationId)->not->toBeNull()
        ->and($response->conversationId)->toBe($agent->currentConversation())
        ->and($response->conversationUser)->toBe($user);
});

test('stream response conversation id is available when continuing an existing conversation', function (): void {
    app()->instance(ConversationStore::class, new FakeConversationStore);

    RememberingAssistantAgent::fake([
        'Fake response',
    ]);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent)
        ->continue('existing-conversation-id', $user)
        ->stream('Test prompt');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->conversationId)->toBe('existing-conversation-id')
        ->and($response->conversationUser)->toBe($user);
});

test('stream response conversation id syncs after late then callbacks', function (): void {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new AssistantAgent)->stream('Test prompt');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    $response->then(function (StreamedAgentResponse $response) use ($user): void {
        $response->withinConversation('late-conversation-id', $user);
    });

    expect($response->conversationId)->toBe('late-conversation-id')
        ->and($response->conversationUser)->toBe($user);
});

test('stream response preserves manually assigned conversation id without a participant', function (): void {
    AssistantAgent::fake([
        'Fake response',
    ]);

    $response = (new AssistantAgent)
        ->stream('Test prompt')
        ->withinConversation('manual-conversation-id');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->conversationId)->toBe('manual-conversation-id')
        ->and($response->conversationUser)->toBeNull();
});

test('an ownerless successful stream does not retain an unpersisted conversation id', function (): void {
    RememberingAssistantAgent::fake([
        'Fake response',
    ]);

    $agent = new RememberingAssistantAgent;
    $response = $agent->stream('Test prompt');

    foreach ($response as $_) {
    }

    expect($agent->currentConversation())->toBeNull()
        ->and($response->conversationId)->toBeNull()
        ->and($response->conversationUser)->toBeNull();
});

test('step middleware that compacts the history does not change what the conversation remembers', function (): void {
    RememberingAssistantAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Fake response',
    ]);

    $user = (object) ['id' => 1];
    $store = new DatabaseConversationStore;
    $conversationId = $store->storeConversation('user', $user->id, 'Compacted conversation');

    $sent = [];

    $response = (new RememberingAssistantAgent)
        ->withTools([new FixedNumberGenerator])
        ->withMiddleware([
            fn (PendingStep $step, Closure $next) => $next($step->isFirstStep() ? $step : $step->withMessages(array_slice($step->messages, -1))),
            function (PendingStep $step, Closure $next) use (&$sent) {
                $sent[] = count($step->messages);

                return $next($step);
            },
        ])
        ->continue($conversationId, $user)
        ->prompt('Test prompt');

    $assistant = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();
    $remembered = $store->getLatestConversationMessages($conversationId, 10);

    expect($sent)->toBe([1, 1])
        ->and($response->text)->toBe('Fake response')
        ->and($assistant->content)->toBe('Fake response')
        ->and(json_decode((string) $assistant->tool_calls, true))->toHaveCount(1)
        ->and(json_decode((string) $assistant->tool_results, true)[0]['result'])->toBe('72019')
        ->and($remembered->map(fn ($message): string => $message::class)->all())->toBe([
            Message::class, AssistantMessage::class, ToolResultMessage::class, AssistantMessage::class,
        ]);
});

function runThroughGateway(CapturingStepGateway $gateway, array $middleware, array $tools = [new FixedNumberGenerator]): void
{
    $gateway->calls = [];

    (new TextGenerationLoop($gateway))->generate(
        Ai::textProviderFor(new AssistantAgent, 'openai'),
        'gpt-test',
        'Be helpful.',
        [new UserMessage('Hi')],
        $tools,
        options: TextGenerationOptions::forAgent((new AssistantAgent)->withMiddleware($middleware)),
    );
}

function shortCircuitingMiddleware(): object
{
    return new class
    {
        public function handle(PendingStep $step, Closure $next): StepResponse
        {
            return new StepResponse('Short-circuited response', [], FinishReason::Stop, new Usage, new Meta);
        }
    };
}
