<?php

use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Gateway\PendingStep;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\RememberingAssistantAgent;
use Tests\Fixtures\FakeConversationStore;
use Tests\Fixtures\RecordingStepGateway;
use Tests\Fixtures\Tools\NamedTool;

test('agent middleware is invoked for each generation step', function () {
    Ai::textProvider('openai')->useTextGateway(new RecordingStepGateway);

    $response = (new AssistantAgent)
        ->withMiddleware([middleware()])
        ->prompt('Test prompt', provider: 'openai');

    expect($response->text)->toEqual('ok')
        ->and($_SERVER['__testing.middleware-step'])->toBeInstanceOf(PendingStep::class);

    unset($_SERVER['__testing.middleware-step']);
});

test('agent middleware is invoked when streaming', function () {
    Ai::textProvider('openai')->useTextGateway(new RecordingStepGateway);

    $response = (new AssistantAgent)
        ->withMiddleware([middleware()])
        ->stream('Test prompt', provider: 'openai');

    foreach ($response as $event) {
        // Drain the stream so the step executes.
    }

    expect($_SERVER['__testing.middleware-step'])->toBeInstanceOf(PendingStep::class);

    unset($_SERVER['__testing.middleware-step']);
});

test('agent middleware is invoked when the agent is faked', function () {
    AssistantAgent::fake(['Fake response']);

    $response = (new AssistantAgent)
        ->withMiddleware([middleware()])
        ->prompt('Test prompt');

    expect($response->text)->toEqual('Fake response')
        ->and($_SERVER['__testing.middleware-step'])->toBeInstanceOf(PendingStep::class);

    unset($_SERVER['__testing.middleware-step']);
});

test('agent middleware runs on every step of a multi-step tool loop', function () {
    $recorder = new RecordingStepGateway([
        new StepResponse('', [new ToolCall('call-1', 'custom_named_tool', [])], FinishReason::ToolCalls, new Usage, new Meta),
        new StepResponse('Done.', [], FinishReason::Stop, new Usage, new Meta),
    ]);

    Ai::textProvider('openai')->useTextGateway($recorder);

    $middleware = new class
    {
        public function handle(PendingStep $step, Closure $next)
        {
            $_SERVER['__testing.middleware-invocations'] = ($_SERVER['__testing.middleware-invocations'] ?? 0) + 1;

            return $next($step->withTools([new NamedTool]));
        }
    };

    $response = (new AssistantAgent)
        ->withMiddleware([$middleware])
        ->prompt('Test prompt', provider: 'openai');

    expect($response->text)->toBe('Done.')
        ->and($recorder->steps)->toBe(2)
        ->and($_SERVER['__testing.middleware-invocations'])->toBe(2)
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('ok');

    unset($_SERVER['__testing.middleware-invocations']);
});

test('agent middleware can short circuit a generation step', function () {
    Ai::textProvider('openai')->useTextGateway(new RecordingStepGateway);

    $response = (new AssistantAgent)
        ->withMiddleware([shortCircuitingMiddleware()])
        ->prompt('Test prompt', provider: 'openai');

    expect($response->text)->toBe('Short-circuited response');
});

test('short-circuited tool calls execute against middleware-transformed tools', function () {
    Ai::textProvider('openai')->useTextGateway(new RecordingStepGateway);

    $addsTool = new class
    {
        public function handle(PendingStep $step, Closure $next)
        {
            return $next($step->withTools([new NamedTool]));
        }
    };

    $shortCircuits = new class
    {
        public function handle(PendingStep $step, Closure $next)
        {
            return $step->context->stepNumber === 0
                ? new StepResponse('', [new ToolCall('call-1', 'custom_named_tool', [])], FinishReason::ToolCalls, new Usage, new Meta)
                : $next($step);
        }
    };

    $response = (new AssistantAgent)
        ->withMiddleware([$addsTool, $shortCircuits])
        ->prompt('Test prompt', provider: 'openai');

    expect($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults->first()->result)->toBe('ok');
});

test('agent middleware can short circuit a streamed generation step', function () {
    $recorder = new RecordingStepGateway;

    Ai::textProvider('openai')->useTextGateway($recorder);

    $response = (new AssistantAgent)
        ->withMiddleware([shortCircuitingMiddleware()])
        ->stream('Test prompt', provider: 'openai');

    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
    }

    expect($recorder->steps)->toBe(0)
        ->and(TextDelta::combine($events))->toBe('Short-circuited response');
});

test('short-circuiting a streamed step emits tool-call events before their tool results', function () {
    Ai::textProvider('openai')->useTextGateway(new RecordingStepGateway);

    $addsTool = new class
    {
        public function handle(PendingStep $step, Closure $next)
        {
            return $next($step->withTools([new NamedTool]));
        }
    };

    $shortCircuits = new class
    {
        public function handle(PendingStep $step, Closure $next)
        {
            return $step->context->stepNumber === 0
                ? new StepResponse('', [new ToolCall('call-1', 'custom_named_tool', [])], FinishReason::ToolCalls, new Usage, new Meta)
                : $next($step);
        }
    };

    $events = [];

    foreach ((new AssistantAgent)->withMiddleware([$addsTool, $shortCircuits])->stream('Test prompt', provider: 'openai') as $event) {
        $events[] = $event;
    }

    $toolCallIndex = collect($events)->search(fn ($event) => $event instanceof ToolCallEvent);
    $toolResultIndex = collect($events)->search(fn ($event) => $event instanceof ToolResultEvent);

    expect($toolCallIndex)->not->toBeFalse()
        ->and($toolResultIndex)->not->toBeFalse()
        ->and($toolCallIndex)->toBeLessThan($toolResultIndex)
        ->and($events[$toolCallIndex]->toolCall->id)->toBe('call-1');
});

test('stream response conversation id is available after remembered conversations stream completes', function () {
    app()->instance(ConversationStore::class, new FakeConversationStore);

    RememberingAssistantAgent::fake([
        'Fake response',
    ]);

    $user = new class
    {
        public int $id = 1;
    };

    $response = (new RememberingAssistantAgent)->forUser($user)->stream('Test prompt');

    foreach ($response as $event) {
        expect($event)->not->toBeNull();
    }

    expect($response->conversationId)->toBe('conversation-123')
        ->and($response->conversationUser)->toBe($user);
});

test('stream response conversation id is available when continuing an existing conversation', function () {
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

test('stream response conversation id syncs after late then callbacks', function () {
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

    $response->then(function (StreamedAgentResponse $response) use ($user) {
        $response->withinConversation('late-conversation-id', $user);
    });

    expect($response->conversationId)->toBe('late-conversation-id')
        ->and($response->conversationUser)->toBe($user);
});

test('stream response preserves manually assigned conversation id without a participant', function () {
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

test('middleware can control the model, instructions, and tools of each step', function () {
    $recorder = new RecordingStepGateway;

    Ai::textProvider('openai')->useTextGateway($recorder);

    $middleware = new class
    {
        public function handle(PendingStep $step, Closure $next)
        {
            return $next(
                $step->withModel('overridden-model')
                    ->withInstructions($step->instructions.' Always answer in French.')
                    ->withTools([new NamedTool])
            );
        }
    };

    (new AssistantAgent)
        ->withMiddleware([$middleware])
        ->prompt('Hello', provider: 'openai');

    expect($recorder->model)->toBe('overridden-model')
        ->and($recorder->instructions)->toEndWith('Always answer in French.')
        ->and($recorder->instructions)->toStartWith('You are a helpful assistant')
        ->and($recorder->tools)->toHaveCount(1);
});

test('the step falls back to the agent when middleware overrides nothing', function () {
    $recorder = new RecordingStepGateway;

    Ai::textProvider('openai')->useTextGateway($recorder);

    (new AssistantAgent)->prompt('Hello', provider: 'openai');

    expect($recorder->instructions)->toBe('You are a helpful assistant that responds extremely concisely to all queries.')
        ->and($recorder->tools)->toBe([]);
});

function shortCircuitingMiddleware(): object
{
    return new class
    {
        public function handle(PendingStep $step, Closure $next)
        {
            return new StepResponse(
                'Short-circuited response',
                [],
                FinishReason::Stop,
                new Usage,
                new Meta,
            );
        }
    };
}

function middleware(): object
{
    return new class
    {
        public function handle(PendingStep $step, Closure $next)
        {
            $_SERVER['__testing.middleware-step'] = $step;

            return $next($step);
        }
    };
}
