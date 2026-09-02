<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\ToolChoice;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
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
            $seen[] = [$step->number, count($step->messages), count($step->steps), $step->previousStep()?->toolCalls[0]->id];

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

    $response
        ->each(fn (): true => true)
        ->then(function (StreamedAgentResponse $response): void {
            $_SERVER['__testing.text'] = $response->text;
        });

    expect($_SERVER['__testing.text'])->toEqual('Fake response')
        ->and($seen)->toBe([0, 1]);

    unset($_SERVER['__testing.text']);
});

test('agent middleware then callback receives the step response before its tools run', function (): void {
    AssistantAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Fake response',
    ]);

    $seen = [];

    (new AssistantAgent)
        ->withTools([new FixedNumberGenerator])
        ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen) {
            return $next($step)->then(function (StepResponse $response) use (&$seen): void {
                $seen[] = $response->finishReason;
            });
        }])
        ->prompt('Test prompt');

    expect($seen)->toBe([FinishReason::ToolCalls, FinishReason::Stop]);
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

test('agent middleware may short-circuit a streamed step', function (): void {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent)
        ->withMiddleware([shortCircuitingMiddleware()])
        ->stream('Test prompt');

    $events = iterator_to_array($response, false);

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(StreamEnd::class);
});

test('agent middleware may narrow the tools for a step', function (): void {
    AssistantAgent::fake([
        new ToolCall('call_1', 'custom_named_tool', []),
        'Fake response',
    ]);

    (new AssistantAgent)
        ->withTools([new FixedNumberGenerator, new NamedTool])
        ->withMiddleware([fn (PendingStep $step, Closure $next) => $next($step->withoutTools('custom_named_tool'))])
        ->prompt('Test prompt');
})->throws(NoSuchToolException::class);

test('agent middleware may change the model and options for a step', function (): void {
    Event::fake([StartingStep::class]);

    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent)
        ->withMiddleware([fn (PendingStep $step, Closure $next) => $next(
            $step->withModel('other-model')->withTemperature(0.2)->withToolChoice('none')->withProviderOptions(['reasoning' => 'low']),
        )])
        ->prompt('Test prompt');

    expect($response->meta->model)->toBe('other-model');

    Event::assertDispatched(StartingStep::class, fn (StartingStep $event): bool => $event->model === 'other-model'
        && $event->options->temperature === 0.2
        && $event->options->toolChoice->mode === ToolChoice::none
        && $event->options->providerOptions('openai') === ['reasoning' => 'low']);
});

test('agent middleware that replaces the history drops the provider continuation token', function (): void {
    $gateway = new class implements StepTextGateway
    {
        public array $contexts = [];

        public function generateTextStep(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): StepResponse
        {
            $this->contexts[] = $stepContext;

            return count($this->contexts) === 1
                ? new StepResponse('', [new ToolCall('call_1', 'FixedNumberGenerator', [])], FinishReason::ToolCalls, new Usage, new Meta, continuationToken: 'resp_1')
                : new StepResponse('Done.', [], FinishReason::Stop, new Usage, new Meta);
        }

        public function generateStreamStep(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): Generator
        {
            yield from [];
        }
    };

    $run = function (Closure $middleware) use ($gateway): array {
        $gateway->contexts = [];

        (new TextGenerationLoop($gateway))->generate(
            Ai::textProviderFor(new AssistantAgent, 'openai'),
            'gpt-test',
            null,
            [new UserMessage('Hi')],
            [new FixedNumberGenerator],
            options: TextGenerationOptions::forAgent((new AssistantAgent)->withMiddleware([$middleware])),
        );

        return array_map(fn (StepContext $context): ?string => $context->continuationToken, $gateway->contexts);
    };

    expect($run(fn (PendingStep $step, Closure $next) => $next($step)))->toBe([null, 'resp_1'])
        ->and($run(fn (PendingStep $step, Closure $next) => $next($step->withMessages(array_slice($step->messages, -1)))))->toBe([null, null]);
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
