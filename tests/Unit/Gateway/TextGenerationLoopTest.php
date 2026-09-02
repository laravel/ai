<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Events\Dispatcher;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Attributes\RepairToolCalls;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\SupportsToolSearch;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Gateway\RunContext;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;

test('it pauses gated tool calls without executing them while running ungated calls immediately', function (): void {
    $gated = new TextGenerationLoopApprovableTool;
    $ungated = new TextGenerationLoopCountingTool;
    $gatedCall = new ToolCall('call-gated', TextGenerationLoopApprovableTool::class, ['value' => 'danger'], 'call-gated');
    $ungatedCall = new ToolCall('call-ungated', TextGenerationLoopCountingTool::class, [], 'call-ungated');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: '',
            toolCalls: [$gatedCall, $ungatedCall],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage,
            meta: new Meta('fake', 'model'),
        ),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$gated, $ungated],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    );

    expect($gated->calls)->toBe(0)
        ->and($ungated->calls)->toBe(1)
        ->and($gateway->generateCalls)->toBe(1)
        ->and($response->hasPendingApprovals())->toBeTrue()
        ->and($response->pendingApprovals)->toHaveCount(1)
        ->and($response->pendingApprovals[0]->id)->toBe('call-gated')
        ->and($response->toolCalls)->toHaveCount(2)
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->id)->toBe('call-ungated')
        ->and($response->toolResults[0]->result)->toBe('counted')
        ->and($gated->approvalToolCallId)->toBe('call-gated');
});

test('it resumes a mixed batch of approved, edited, and rejected calls', function (): void {
    $tool = new TextGenerationLoopApprovableTool;
    $approvedCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-1');
    $editedCall = new ToolCall('call-2', TextGenerationLoopApprovableTool::class, ['value' => 'original'], 'call-2');
    $rejectedCall = new ToolCall('call-3', TextGenerationLoopApprovableTool::class, ['value' => 'blocked'], 'call-3');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: 'done',
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new Usage,
            meta: new Meta('fake', 'model'),
        ),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$approvedCall, $editedCall, $rejectedCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        [
            'call-1' => Decision::approve(),
            'call-2' => Decision::edit(['value' => 'edited']),
            'call-3' => Decision::reject('Not that file'),
        ],
    );

    expect($tool->calls)->toBe(2)
        ->and($tool->handledArguments)->toBe([['value' => 'approved'], ['value' => 'edited']])
        ->and($gateway->generateCalls)->toBe(1)
        ->and($response->hasPendingApprovals())->toBeFalse()
        ->and($response->toolResults)->toHaveCount(3)
        ->and($response->toolResults->firstWhere('id', 'call-1')->result)->toBe('handled approved')
        ->and($response->toolResults->firstWhere('id', 'call-2')->result)->toBe('handled edited')
        ->and($response->toolResults->firstWhere('id', 'call-3')->result)->toBe('Not that file')
        ->and($response->text)->toBe('done');
});

test('mismatched or stale approval decisions are rejected', function (): void {
    $tool = new TextGenerationLoopApprovableTool;
    $pendingCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'pending'], 'call-1');

    // A decision naming an id that is not pending does not match.
    expect(fn () => (new TextGenerationLoop(new TextGenerationLoopFakeGateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$pendingCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['call-2' => Decision::approve()],
    ))->toThrow(ApprovalMismatchException::class);

    $settledCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-1');

    // An approval arriving after the gated call already has a result is stale.
    expect(fn () => (new TextGenerationLoop(new TextGenerationLoopFakeGateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [
            new AssistantMessage('', collect([$settledCall])),
            new ToolResultMessage(collect([
                new ToolResultData('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'handled approved', 'call-1'),
            ])),
        ],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['*' => Decision::approve()],
    ))->toThrow(ApprovalMismatchException::class, 'There are no tool calls pending approval.');
});

test('it emits streamed approval requests without executing gated tools', function (): void {
    $tool = new TextGenerationLoopApprovableTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'danger'], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $toolCall, time())],
            returns: new StepResponse('', [$toolCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    ));

    $approvalEvents = collect($events)->whereInstanceOf(ToolApprovalRequest::class);

    expect($tool->calls)->toBe(0)
        ->and($approvalEvents)->toHaveCount(1)
        ->and($approvalEvents->first()->pendingApprovals[0]->id)->toBe('call-1')
        ->and(collect($events)->whereInstanceOf(ToolResultEvent::class))->toHaveCount(0)
        ->and(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(1);
});

test('it resumes a paused step running only the still-pending gated call', function (): void {
    $gated = new TextGenerationLoopApprovableTool;
    $ungated = new TextGenerationLoopCountingTool;
    $gatedCall = new ToolCall('call-gated', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-gated');
    $ungatedCall = new ToolCall('call-ungated', TextGenerationLoopCountingTool::class, [], 'call-ungated');

    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [
            new AssistantMessage('', collect([$gatedCall, $ungatedCall])),
            new ToolResultMessage(collect([
                new ToolResultData('call-ungated', TextGenerationLoopCountingTool::class, [], 'counted', 'call-ungated'),
            ])),
        ],
        [$gated, $ungated],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['call-gated' => Decision::approve()],
    );

    expect($gated->calls)->toBe(1)
        ->and($ungated->calls)->toBe(0)
        ->and($response->hasPendingApprovals())->toBeFalse()
        ->and($response->toolResults->pluck('id')->all())->toBe(['call-ungated', 'call-gated'])
        ->and($response->text)->toBe('done');
});

test('a bare rejection stops the loop without another model call', function (): void {
    $tool = new TextGenerationLoopApprovableTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'blocked'], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway;

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$toolCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['call-1' => Decision::reject()],
    );

    expect($tool->calls)->toBe(0)
        ->and($gateway->generateCalls)->toBe(0)
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->result)->toBe('The user rejected this tool call.');
});

test('a gated tool with approval disabled executes without pausing', function (): void {
    $tool = (new TextGenerationLoopApprovableTool)->withoutApproval();
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'safe'], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('', [$toolCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    );

    expect($tool->calls)->toBe(1)
        ->and($response->hasPendingApprovals())->toBeFalse()
        ->and($response->text)->toBe('done');
});

test('a bare rejection beside an approved call records the executed result and stops the loop', function (): void {
    $tool = new TextGenerationLoopApprovableTool;
    $firstCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-1');
    $secondCall = new ToolCall('call-2', TextGenerationLoopApprovableTool::class, ['value' => 'blocked'], 'call-2');
    $gateway = new TextGenerationLoopFakeGateway;

    $recorded = null;

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$firstCall, $secondCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['call-1' => Decision::approve(), 'call-2' => Decision::reject()],
        function (array $toolResults) use (&$recorded) {
            $recorded = $toolResults;
        },
    );

    expect($tool->calls)->toBe(1)
        ->and($gateway->generateCalls)->toBe(0)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->toolResults[0]->result)->toBe('handled approved')
        ->and($response->toolResults[1]->result)->toBe('The user rejected this tool call.')
        ->and($recorded)->not->toBeNull()
        ->and(collect($recorded)->firstWhere('id', 'call-1')->result)->toBe('handled approved');
});

test('a streamed bare rejection stops the loop even beside an approved call', function (): void {
    $tool = new TextGenerationLoopApprovableTool;
    $firstCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-1');
    $secondCall = new ToolCall('call-2', TextGenerationLoopApprovableTool::class, ['value' => 'blocked'], 'call-2');
    $gateway = new TextGenerationLoopFakeGateway;

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$firstCall, $secondCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['call-1' => Decision::approve(), 'call-2' => Decision::reject()],
    ));

    $toolResults = collect($events)->whereInstanceOf(ToolResultEvent::class);

    expect($tool->calls)->toBe(1)
        ->and($gateway->streamCalls)->toBe(0)
        ->and($toolResults)->toHaveCount(2)
        ->and($toolResults->firstWhere(fn ($event) => $event->toolResult->id === 'call-1')->successful)->toBeTrue()
        ->and($toolResults->firstWhere(fn ($event) => $event->toolResult->id === 'call-2')->denied)->toBeTrue()
        ->and(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(1);
});

test('a gate that has relaxed since the pause can still be resumed', function (): void {
    $tool = (new TextGenerationLoopApprovableTool)->withoutApproval();
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$toolCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['call-1' => Decision::approve()],
    );

    expect($tool->calls)->toBe(1)
        ->and($response->hasPendingApprovals())->toBeFalse()
        ->and($response->text)->toBe('done');
});

test('a relaxed gate with no decision fails closed instead of auto-running the tool', function (): void {
    $tool = (new TextGenerationLoopApprovableTool)->withoutApproval();
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$toolCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        [],
    );

    expect($tool->calls)->toBe(0)
        ->and($response->toolResults->firstWhere('id', 'call-1')->result)->toBe('The user rejected this tool call.');
});

test('a resumed mixed batch merges its results into the pause turn answering message', function (): void {
    $gated = new TextGenerationLoopApprovableTool;
    $ungated = new TextGenerationLoopCountingTool;
    $gatedCall = new ToolCall('call-gated', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-gated');
    $ungatedCall = new ToolCall('call-ungated', TextGenerationLoopCountingTool::class, [], 'call-ungated');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [
            new AssistantMessage('', collect([$ungatedCall, $gatedCall])),
            new ToolResultMessage(collect([
                new ToolResultData('call-ungated', TextGenerationLoopCountingTool::class, [], 'counted', 'call-ungated'),
            ])),
        ],
        [$gated, $ungated],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['call-gated' => Decision::approve()],
    );

    $toolResultMessages = $response->messages->whereInstanceOf(ToolResultMessage::class);

    expect($gated->calls)->toBe(1)
        ->and($ungated->calls)->toBe(0)
        ->and($toolResultMessages)->toHaveCount(1)
        ->and($toolResultMessages->first()->toolResults)->toHaveCount(2)
        ->and($response->text)->toBe('done');
});

test('a plain generation settles abandoned pauses before calling the model, each keeping its own placeholder', function (): void {
    $tool = new TextGenerationLoopApprovableTool;
    $firstCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'danger'], 'call-1');
    $secondCall = new ToolCall('call-2', TextGenerationLoopApprovableTool::class, ['value' => 'also danger'], 'call-2');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('Sure, moving on.', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [
            new AssistantMessage('', collect([$firstCall])),
            new Message('user', 'Never mind, do something else.'),
            new AssistantMessage('', collect([$secondCall])),
            new Message('user', 'Never mind that either, just say hi.'),
        ],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    );

    $settled = $gateway->messages[0];

    expect($tool->calls)->toBe(0)
        ->and($settled)->toHaveCount(6)
        ->and($settled[1])->toBeInstanceOf(ToolResultMessage::class)
        ->and($settled[1]->toolResults->first()->id)->toBe('call-1')
        ->and($settled[1]->toolResults->first()->result)->toContain('not approved')
        ->and($settled[2]->content)->toBe('Never mind, do something else.')
        ->and($settled[4])->toBeInstanceOf(ToolResultMessage::class)
        ->and($settled[4]->toolResults->first()->id)->toBe('call-2')
        ->and($settled[4]->toolResults->first()->result)->toContain('not approved')
        ->and($settled[5]->content)->toBe('Never mind that either, just say hi.')
        ->and($response->text)->toBe('Sure, moving on.');
});

test('a default decision approves every pending call while an explicit decision overrides it', function (): void {
    $gated = new TextGenerationLoopApprovableTool;
    $ungated = new TextGenerationLoopCountingTool;
    $approvedCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-1');
    $rejectedCall = new ToolCall('call-2', TextGenerationLoopApprovableTool::class, ['value' => 'blocked'], 'call-2');
    $ungatedCall = new ToolCall('call-ungated', TextGenerationLoopCountingTool::class, [], 'call-ungated');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$approvedCall, $rejectedCall, $ungatedCall]))],
        [$gated, $ungated],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['call-2' => Decision::reject('Wrong file'), '*' => Decision::approve()],
    );

    expect($gated->calls)->toBe(1)
        ->and($gated->handledArguments)->toBe([['value' => 'approved']])
        ->and($ungated->calls)->toBe(1)
        ->and($response->hasPendingApprovals())->toBeFalse()
        ->and($response->toolResults)->toHaveCount(3)
        ->and($response->toolResults->firstWhere('id', 'call-2')->result)->toBe('Wrong file')
        ->and($response->text)->toBe('done');
});

test('a default decision does not excuse decisions for unknown tool calls', function (): void {
    $tool = new TextGenerationLoopApprovableTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'pending'], 'call-1');

    expect(fn () => (new TextGenerationLoop(new TextGenerationLoopFakeGateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$toolCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['call-2' => Decision::approve(), '*' => Decision::approve()],
    ))->toThrow(ApprovalMismatchException::class);
});

test('a streamed default rejection marks the tool results as unsuccessful', function (): void {
    $tool = new TextGenerationLoopApprovableTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'blocked'], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new TextDelta('text-delta', 'message-1', 'Understood.', time())],
            returns: new StepResponse('Understood.', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$toolCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['*' => Decision::reject('Not now')],
    ));

    $toolResults = collect($events)->whereInstanceOf(ToolResultEvent::class);

    expect($tool->calls)->toBe(0)
        ->and($toolResults)->toHaveCount(1)
        ->and($toolResults->first()->successful)->toBeFalse()
        ->and($toolResults->first()->denied)->toBeTrue()
        ->and($toolResults->first()->error)->toBe('Not now')
        ->and(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(1);
});

test('it does not execute tool calls on the final generation step', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: '',
            toolCalls: [new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1')],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage,
            meta: new Meta('fake', 'model'),
            continuationToken: 'response-1',
        ),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 1),
        null,
    );

    expect($tool->calls)->toBe(0)
        ->and($gateway->generateCalls)->toBe(1)
        ->and($gateway->contexts[0]->isFinalStep)->toBeTrue()
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->result)->toBe('The agent reached its maximum number of steps without running this tool call.')
        ->and($response->toolResults[0]->successful())->toBeFalse()
        ->and($response->toolResults[0]->error())->toBe('The agent reached its maximum number of steps without running this tool call.')
        ->and($response->steps)->toHaveCount(1)
        ->and($response->steps->first()->toolResults)->toHaveCount(1);
});

test('it preserves the exhausted result for unknown tools without the repair attribute', function (): void {
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('', [new ToolCall('call-1', 'MissingTool', [], 'call-1')], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [new TextGenerationLoopCountingTool],
        null,
        new TextGenerationOptions(maxSteps: 1),
        null,
    );

    expect($gateway->generateCalls)->toBe(1)
        ->and($gateway->contexts[0]->isFinalStep)->toBeTrue()
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->result)->toBe('The agent reached its maximum number of steps without running this tool call.');
});

test('it holds stream end until the streamed tool loop is complete', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $firstToolCall = new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $firstToolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$firstToolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
        ),
        textGenerationLoopStreamStep(
            events: [new TextDelta('text-delta', 'message-1', 'Done', time())],
            returns: new StepResponse(text: 'Done', toolCalls: [], finishReason: FinishReason::Stop, usage: new Usage(5, 2), meta: new Meta('fake', 'model'), continuationToken: 'response-2'),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    ));

    $streamEnds = collect($events)->whereInstanceOf(StreamEnd::class);

    expect($tool->calls)->toBe(1)
        ->and($gateway->streamCalls)->toBe(2)
        ->and($streamEnds)->toHaveCount(1)
        ->and(collect($events)->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
        ->and($streamEnds->first()->reason)->toBe(FinishReason::Stop->value)
        ->and($streamEnds->first()->usage->promptTokens)->toBe(15)
        ->and($streamEnds->first()->usage->completionTokens)->toBe(3);
});

test('it does not execute streamed tool calls on the final step', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $toolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 1),
        null,
    ));

    expect($tool->calls)->toBe(0)
        ->and($gateway->streamCalls)->toBe(1)
        ->and(collect($events)->whereInstanceOf(ToolResultEvent::class))->toHaveCount(1)
        ->and(collect($events)->whereInstanceOf(ToolResultEvent::class)->first()->successful)->toBeFalse()
        ->and(collect($events)->whereInstanceOf(ToolResultEvent::class)->first()->error)->toBe('The agent reached its maximum number of steps without running this tool call.')
        ->and(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(1);
});

test('it clamps non-positive maxSteps to at least one turn', function (int $maxSteps): void {
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: 'hi',
            toolCalls: [],
            finishReason: FinishReason::Stop,
            usage: new Usage(1, 1),
            meta: new Meta('fake', 'model'),
        ),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        new TextGenerationOptions(maxSteps: $maxSteps),
        null,
    );

    expect($gateway->generateCalls)->toBe(1)
        ->and($response->text)->toBe('hi');
})->with([
    'zero' => 0,
    'negative' => -3,
]);

test('it accumulates streamed usage across multi-step turns', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call', $toolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model')),
        ),
        textGenerationLoopStreamStep(
            events: [new TextDelta('delta', 'msg-1', 'done', time())],
            returns: new StepResponse(text: 'done', toolCalls: [], finishReason: FinishReason::Stop, usage: new Usage(5, 2), meta: new Meta('fake', 'model')),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    ));

    $streamEnd = collect($events)->whereInstanceOf(StreamEnd::class)->first();

    expect($streamEnd)->toBeInstanceOf(StreamEnd::class)
        ->and($streamEnd->usage->promptTokens)->toBe(15)
        ->and($streamEnd->usage->completionTokens)->toBe(3)
        ->and($streamEnd->reason)->toBe(FinishReason::Stop->value);
});

test('it throws when generation tool calls do not match local tools', function (): void {
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: '',
            toolCalls: [new ToolCall('call-1', 'MissingTool', [], 'call-1')],
            finishReason: FinishReason::ToolCalls,
            usage: new Usage(10, 1),
            meta: new Meta('fake', 'model'),
            continuationToken: 'response-1',
        ),
    ]);

    expect(fn () => (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        new TextGenerationOptions(agent: new TextGenerationLoopAgent),
        null,
    ))->toThrow(NoSuchToolException::class, "Model tried to call unavailable tool 'MissingTool'.");
});

test('it throws when streaming tool calls do not match local tools', function (): void {
    $toolCall = new ToolCall('call-1', 'MissingTool', [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $toolCall, time())],
            returns: new StepResponse(text: '', toolCalls: [$toolCall], finishReason: FinishReason::ToolCalls, usage: new Usage(10, 1), meta: new Meta('fake', 'model'), continuationToken: 'response-1'),
        ),
    ]);

    expect(fn () => iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        null,
        null,
    )))->toThrow(NoSuchToolException::class);
});

test('it repairs missing generation tool calls for agents with the repair attribute', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('', [new ToolCall('call-1', 'MissingTool', [], 'call-1')], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        new StepResponse('Done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool, new WebSearch],
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new TextGenerationLoopRepairingAgent),
        null,
    );

    expect($gateway->generateCalls)->toBe(2)
        ->and($response->text)->toBe('Done')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->result)->toBe("Tool 'MissingTool' does not exist. Available tools: TextGenerationLoopCountingTool.");
});

test('it budgets an implicit step for a repaired tool call', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $toolCall = new ToolCall('call-2', TextGenerationLoopCountingTool::class, [], 'call-2');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('', [new ToolCall('call-1', 'MissingTool', [], 'call-1')], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        new StepResponse('', [$toolCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        new StepResponse('Done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(agent: new TextGenerationLoopRepairingAgent),
        null,
    );

    expect($gateway->generateCalls)->toBe(3)
        ->and($gateway->contexts[1]->isFinalStep)->toBeFalse()
        ->and($gateway->contexts[2]->isFinalStep)->toBeTrue()
        ->and($tool->calls)->toBe(1)
        ->and($response->text)->toBe('Done');
});

test('it executes local tools when provider-hosted tools are also registered', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopCountingTool::class, [], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('', [$toolCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        new StepResponse('Done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [new WebSearch, $tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    );

    expect($tool->calls)->toBe(1)
        ->and($gateway->generateCalls)->toBe(2)
        ->and($response->text)->toBe('Done');
});

test('it repairs missing streamed tool calls for agents with the repair attribute', function (): void {
    $tool = new TextGenerationLoopCountingTool;
    $missingToolCall = new ToolCall('call-1', 'MissingTool', [], 'call-1');
    $toolCall = new ToolCall('call-2', TextGenerationLoopCountingTool::class, [], 'call-2');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event', $missingToolCall, time())],
            returns: new StepResponse('', [$missingToolCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        ),
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('tool-call-event-2', $toolCall, time())],
            returns: new StepResponse('', [$toolCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        ),
        textGenerationLoopStreamStep(
            events: [new TextDelta('text-delta', 'message-1', 'Done', time())],
            returns: new StepResponse('Done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(agent: new TextGenerationLoopRepairingAgent),
        null,
    ));

    $toolResults = collect($events)->whereInstanceOf(ToolResultEvent::class)->values();
    $repairResult = $toolResults[0];

    expect($gateway->streamCalls)->toBe(3)
        ->and($tool->calls)->toBe(1)
        ->and($toolResults)->toHaveCount(2)
        ->and($repairResult->toolResult->result)->toBe("Tool 'MissingTool' does not exist. Available tools: TextGenerationLoopCountingTool.")
        ->and($repairResult->successful)->toBeFalse()
        ->and($repairResult->error)->toBe($repairResult->toolResult->result)
        ->and($toolResults[1]->successful)->toBeTrue();
});

test('it reports no available tools while repairing missing tool calls', function (): void {
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('', [new ToolCall('call-1', 'MissingTool', [], 'call-1')], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        new StepResponse('Done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        new TextGenerationOptions(maxSteps: 2, agent: new TextGenerationLoopRepairingAgent),
        null,
    );

    expect($response->toolResults[0]->result)->toBe("Tool 'MissingTool' does not exist. Available tools: none.");
});

test('it throws when an approved tool is unavailable despite the repair attribute', function (): void {
    $toolCall = new ToolCall('call-1', 'MissingTool', [], 'call-1');

    expect(fn () => (new TextGenerationLoop(new TextGenerationLoopFakeGateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$toolCall]))],
        [],
        null,
        new TextGenerationOptions(agent: new TextGenerationLoopRepairingAgent),
        null,
        ['call-1' => Decision::approve()],
    ))->toThrow(NoSuchToolException::class);
});

test('it throws when a turn completes without a step response', function (): void {
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(events: [new TextDelta('text-delta', 'message-1', 'partial', time())]),
    ]);

    expect(fn (): array => iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        null,
        null,
    )))->toThrow(StreamErrorException::class, 'The provider ended the stream without completing the step.');
});

test('it yields the error and then throws it when a turn errors without a stream end', function (): void {
    $error = new Error('error-1', 'server_error', 'Server overloaded', false, time());

    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(events: [$error]),
    ]);

    $events = [];
    $thrown = null;

    try {
        foreach ((new TextGenerationLoop($gateway))->stream(
            'invocation-1',
            textGenerationLoopProvider(),
            'model',
            null,
            [],
            [],
            null,
            null,
            null,
        ) as $event) {
            $events[] = $event;
        }
    } catch (StreamErrorException $exception) {
        $thrown = $exception;
    }

    // The consumer still sees the provider's own error event before the step fails...
    expect(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(0)
        ->and(collect($events)->whereInstanceOf(Error::class))->toHaveCount(1)
        ->and($thrown?->error)->toBe($error)
        ->and($thrown?->getMessage())->toBe('Server overloaded');
});

test('it rejects tool search on a provider that does not support it before calling the gateway', function (): void {
    $gateway = new TextGenerationLoopFakeGateway;
    $tools = [new TextGenerationLoopCountingTool, new ToolSearch(tools: [new TextGenerationLoopCountingTool])];

    expect(fn () => (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        $tools,
        null,
        null,
        null,
    ))->toThrow(LogicException::class, 'does not support tool search');

    expect($gateway->generateCalls)->toBe(0);
});

test('it rejects tool search on a streamed generation for an unsupported provider', function (): void {
    $gateway = new TextGenerationLoopFakeGateway;
    $tools = [new TextGenerationLoopCountingTool, new ToolSearch(tools: [new TextGenerationLoopCountingTool])];

    expect(fn () => iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        $tools,
        null,
        null,
        null,
    )))->toThrow(LogicException::class, 'does not support tool search');

    expect($gateway->streamCalls)->toBe(0);
});

test('it rejects more than one tool search wrapper on a supporting provider', function (): void {
    $gateway = new TextGenerationLoopFakeGateway;
    $tools = [
        new TextGenerationLoopCountingTool,
        new ToolSearch(tools: [new TextGenerationLoopCountingTool]),
        new ToolSearch(tools: [new TextGenerationLoopCountingTool]),
    ];

    expect(fn () => (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopToolSearchProvider(),
        'model',
        null,
        [],
        $tools,
        null,
        null,
        null,
    ))->toThrow(LogicException::class, 'single tool search wrapper');

    expect($gateway->generateCalls)->toBe(0);
});

test('it allows a single tool search wrapper on a supporting provider', function (): void {
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopToolSearchProvider(),
        'model',
        null,
        [],
        [new TextGenerationLoopCountingTool, new ToolSearch(tools: [new TextGenerationLoopCountingTool])],
        null,
        null,
        null,
    );

    expect($gateway->generateCalls)->toBe(1)
        ->and($response->text)->toBe('done');
});

function textGenerationLoopProvider(): TextProvider
{
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('name')->andReturn('fake');

    return $provider;
}

function textGenerationLoopToolSearchProvider(): TextProvider
{
    $provider = Mockery::mock(TextProvider::class, SupportsToolSearch::class);
    $provider->shouldReceive('name')->andReturn('fake');

    return $provider;
}

/** @param  array<int, object>  $events */
function textGenerationLoopStreamStep(array $events = [], ?StepResponse $returns = null): array
{
    return [$events, $returns];
}

class TextGenerationLoopFakeGateway implements StepTextGateway
{
    public int $generateCalls = 0;

    public int $streamCalls = 0;

    /** @var StepContext[] */
    public array $contexts = [];

    public array $messages = [];

    public function __construct(
        public array $steps = [],
        public array $streams = [],
    ) {}

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $this->generateCalls++;
        $this->contexts[] = $stepContext;
        $this->messages[] = $messages;

        return array_shift($this->steps);
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $this->streamCalls++;
        $this->contexts[] = $stepContext;

        [$events, $result] = array_shift($this->streams);

        foreach ($events as $event) {
            yield $event;
        }

        return $result;
    }
}

class TextGenerationLoopCountingTool implements Tool
{
    public int $calls = 0;

    public function description(): string
    {
        return 'Counts invocations.';
    }

    public function handle(Request $request): string
    {
        $this->calls++;

        return 'counted';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class TextGenerationLoopApprovableTool extends TextGenerationLoopCountingTool implements Approvable
{
    use InteractsWithApprovals;

    public array $handledArguments = [];

    public ?string $approvalToolCallId = null;

    protected function needsApproval(Request $request): Approval|bool
    {
        $this->approvalToolCallId = $request->toolCallId();

        return Approval::required('Needs a human');
    }

    public function handle(Request $request): string
    {
        $this->calls++;
        $this->handledArguments[] = $request->all();

        return 'handled '.$request['value'];
    }
}

#[RepairToolCalls]
class TextGenerationLoopRepairingAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}

class TextGenerationLoopAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}

class ExtendedTextGenerationLoop extends TextGenerationLoop
{
    protected function stepToolResults(StepResponse $result, bool $isFinalStep, array $tools, ?RunContext $context = null): array
    {
        return parent::stepToolResults($result, $isFinalStep, $tools, $context);
    }

    protected function approvalAwareToolResults(array $toolCalls, array $tools, bool $isFinalStep = false, ?RunContext $context = null): array
    {
        return parent::approvalAwareToolResults($toolCalls, $tools, $isFinalStep, $context);
    }
}

test('it preserves the generation loop protected extension signatures', function (): void {
    expect(new ExtendedTextGenerationLoop(new TextGenerationLoopFakeGateway))->toBeInstanceOf(TextGenerationLoop::class);
});

test('an approval resume settles an earlier abandoned pause', function (): void {
    $tool = new TextGenerationLoopApprovableTool;
    $abandonedCall = new ToolCall('call-old', TextGenerationLoopApprovableTool::class, ['value' => 'abandoned'], 'call-old');
    $pendingCall = new ToolCall('call-new', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-new');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [
            new AssistantMessage('', collect([$abandonedCall])),
            new Message('user', 'Never mind, use the other file.'),
            new AssistantMessage('', collect([$pendingCall])),
        ],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ['call-new' => Decision::approve()],
    );

    $settled = $gateway->messages[0][1];

    expect($tool->handledArguments)->toBe([['value' => 'approved']])
        ->and($settled)->toBeInstanceOf(ToolResultMessage::class)
        ->and($settled->toolResults->first()->id)->toBe('call-old')
        ->and($settled->toolResults->first()->result)->toContain('not approved')
        ->and($response->text)->toBe('done');
});

test('a gated tool call on the final step pauses instead of being exhausted', function (): void {
    $gated = new TextGenerationLoopApprovableTool;
    $ungated = new TextGenerationLoopCountingTool;
    $gatedCall = new ToolCall('call-gated', TextGenerationLoopApprovableTool::class, ['value' => 'danger'], 'call-gated');
    $ungatedCall = new ToolCall('call-ungated', TextGenerationLoopCountingTool::class, [], 'call-ungated');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('', [$gatedCall, $ungatedCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$gated, $ungated],
        null,
        new TextGenerationOptions(maxSteps: 1),
        null,
    );

    expect($gated->calls)->toBe(0)
        ->and($ungated->calls)->toBe(0)
        ->and($response->hasPendingApprovals())->toBeTrue()
        ->and($response->pendingApprovals)->toHaveCount(1)
        ->and($response->pendingApprovals[0]->id)->toBe('call-gated')
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->id)->toBe('call-ungated')
        ->and($response->toolResults[0]->result)->toContain('maximum number of steps');
});

test('a pre-validated streamed resume executes the approved tool exactly once', function (): void {
    $tool = new TextGenerationLoopApprovableTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new TextDelta('text-delta', 'message-1', 'done', time())],
            returns: new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
        ),
    ]);

    $loop = new TextGenerationLoop($gateway);
    $messages = [new AssistantMessage('', collect([$toolCall]))];
    $decision = ['call-1' => Decision::approve()];

    $loop->validateApproval($decision, $messages, [$tool]);

    iterator_to_array($loop->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        $messages,
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        $decision,
    ));

    expect($tool->calls)->toBe(1);
});

function textGenerationLoopAgentTool(string $text = 'sub-agent result'): AgentTool
{
    $response = new StreamableAgentResponse('sub-invocation', fn (): Generator => yield from [
        (new TextDelta('sub-delta', 'sub-message', $text, time()))->withInvocationId('sub-invocation'),
        (new StreamEnd('sub-end', FinishReason::Stop->value, new Usage(3, 4), time()))->withInvocationId('sub-invocation'),
    ], new Meta('fake', 'sub-model'));

    $agent = Mockery::mock(Agent::class.','.CanActAsTool::class);
    $agent->shouldReceive('name')->andReturn('research_agent');
    $agent->shouldReceive('stream')->once()->andReturn($response);
    $agent->shouldNotReceive('prompt');

    return new AgentTool($agent);
}

test('a sub-agent streams preliminary tool results and returns its response during the streamed tool loop', function (): void {
    $tool = textGenerationLoopAgentTool();
    $toolCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [new ToolCallEvent('event-1', $toolCall, time())],
            returns: new StepResponse('', [$toolCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        ),
        textGenerationLoopStreamStep(
            events: [new TextDelta('text-delta', 'message-2', 'done', time())],
            returns: new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    ));

    $activity = array_values(array_filter(
        $events,
        fn (object $event): bool => $event instanceof ToolResultEvent && $event->preliminary(),
    ));
    $firstActivityIndex = null;
    $toolResultIndex = null;

    foreach ($events as $index => $event) {
        if ($firstActivityIndex === null && $event instanceof ToolResultEvent && $event->preliminary()) {
            $firstActivityIndex = $index;
        }

        if ($toolResultIndex === null && $event instanceof ToolResultEvent && ! $event->preliminary()) {
            $toolResultIndex = $index;
        }
    }

    $toolResultEvents = array_values(array_filter(
        $events,
        fn (object $event): bool => $event instanceof ToolResultEvent && ! $event->preliminary(),
    ));

    expect($activity)->toHaveCount(2)
        ->and($activity[0]->invocationId)->toBe('invocation-1')
        ->and($activity[0]->toolResult->id)->toBe('call-sub-agent')
        ->and($activity[0]->toolResult->result['type'])->toBe('text_delta')
        ->and($activity[0]->toolResult->result['invocation_id'])->toBe('sub-invocation')
        ->and($activity[1]->toolResult->result['type'])->toBe('stream_end')
        ->and($firstActivityIndex)->toBeLessThan($toolResultIndex)
        ->and($toolResultEvents[0]->toolResult->result)->toBe('sub-agent result');

    expect($activity[0]->preliminaryOutput)->toBe('sub-agent result')
        ->and($activity[1]->preliminaryOutput)->toBe($toolResultEvents[0]->toolResult->result);
});

test('a sub-agent runs synchronously through the non-streaming loop', function (): void {
    $agent = Mockery::mock(Agent::class.','.CanActAsTool::class);
    $agent->shouldReceive('name')->andReturn('research_agent');
    $agent->shouldReceive('prompt')->once()->andReturn(new AgentResponse(
        'sub-invocation', 'synchronous result', new Usage(3, 4), new Meta('fake', 'sub-model')
    ));
    $agent->shouldNotReceive('stream');

    $tool = new AgentTool($agent);
    $toolCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('', [$toolCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    );

    expect($response->text)->toBe('done')
        ->and($response->toolResults[0]->result)->toBe('synchronous result');
});

test('a sub-agent is not executed on the final streamed step', function (): void {
    $agent = Mockery::mock(Agent::class.','.CanActAsTool::class);
    $agent->shouldReceive('name')->andReturn('research_agent');
    $agent->shouldNotReceive('prompt');
    $agent->shouldNotReceive('stream');

    $tool = new AgentTool($agent);
    $toolCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [],
            returns: new StepResponse('', [$toolCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 1),
        null,
    ));

    $activity = array_filter(
        $events,
        fn (object $event): bool => $event instanceof ToolResultEvent && $event->preliminary(),
    );
    $toolResultEvents = array_values(array_filter(
        $events,
        fn (object $event): bool => $event instanceof ToolResultEvent && ! $event->preliminary(),
    ));

    expect($activity)->toHaveCount(0)
        ->and($toolResultEvents[0]->toolResult->result)->toContain('maximum number of steps');
});

test('multiple sub-agent calls stream activity with their own tool call ids', function (): void {
    $responses = collect(['first result', 'second result']);
    $agent = Mockery::mock(Agent::class.','.CanActAsTool::class);
    $agent->shouldReceive('name')->andReturn('research_agent');
    $agent->shouldReceive('stream')->twice()->andReturnUsing(function () use ($responses): StreamableAgentResponse {
        $text = $responses->shift();

        return new StreamableAgentResponse('sub-invocation', fn (): Generator => yield new TextDelta(
            'sub-delta', 'sub-message', $text, time()
        ), new Meta('fake', 'sub-model'));
    });

    $tool = new AgentTool($agent);
    $firstCall = new ToolCall('call-first', 'research_agent', ['task' => 'First'], 'call-first');
    $secondCall = new ToolCall('call-second', 'research_agent', ['task' => 'Second'], 'call-second');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [],
            returns: new StepResponse('', [$firstCall, $secondCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        ),
        textGenerationLoopStreamStep(
            events: [],
            returns: new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    ));

    $toolCallIds = collect($events)
        ->whereInstanceOf(ToolResultEvent::class)
        ->filter(fn (ToolResultEvent $event): bool => $event->preliminary())
        ->map(fn (ToolResultEvent $event): string => $event->toolResult->id)
        ->values()
        ->all();

    expect($toolCallIds)->toBe(['call-first', 'call-second']);
});

test('tool invocation events fire around a sub-agent in the streamed loop', function (): void {
    $tool = textGenerationLoopAgentTool();
    $toolCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [],
            returns: new StepResponse('', [$toolCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        ),
        textGenerationLoopStreamStep(
            events: [],
            returns: new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
        ),
    ]);

    $invoking = [];
    $invoked = [];

    $events = new Dispatcher;

    $events->listen(InvokingTool::class, function (InvokingTool $event) use (&$invoking): void {
        $invoking[] = $event->tool::class;
    });

    $events->listen(ToolInvoked::class, function (ToolInvoked $event) use (&$invoked): void {
        $invoked[] = $event->result;
    });

    $provider = textGenerationLoopProvider();

    iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        $provider,
        'model',
        null,
        [],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        context: new RunContext('invocation-1', Mockery::mock(Agent::class), $provider, 'model', $events),
    ));

    expect($invoking)->toBe([AgentTool::class])
        ->and($invoked)->toBe(['sub-agent result']);
});

test('a gated tool pauses the streamed loop while a sub-agent still streams its activity', function (): void {
    $gated = new TextGenerationLoopApprovableTool;
    $subAgent = textGenerationLoopAgentTool();
    $gatedCall = new ToolCall('call-gated', TextGenerationLoopApprovableTool::class, ['value' => 'danger'], 'call-gated');
    $subAgentCall = new ToolCall('call-sub-agent', 'research_agent', ['task' => 'Research'], 'call-sub-agent');
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(
            events: [],
            returns: new StepResponse('', [$gatedCall, $subAgentCall], FinishReason::ToolCalls, new Usage, new Meta('fake', 'model')),
        ),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [$gated, $subAgent],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    ));

    $activity = array_values(array_filter(
        $events,
        fn (object $event): bool => $event instanceof ToolResultEvent && $event->preliminary(),
    ));
    $approvalRequests = array_values(array_filter($events, fn (object $event): bool => $event instanceof ToolApprovalRequest));

    expect($gated->calls)->toBe(0)
        ->and($activity)->toHaveCount(2)
        ->and($approvalRequests)->toHaveCount(1)
        ->and($approvalRequests[0]->pendingApprovals->first()->id)->toBe('call-gated');
});
