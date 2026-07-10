<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\ToolApproval;
use Laravel\Ai\Concerns\InteractsWithApproval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\Request;

test('it pauses approvable tool calls without executing them', function () {
    $tool = new TextGenerationLoopApprovableTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'danger'], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse(
            text: '',
            toolCalls: [$toolCall],
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
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
    );

    expect($tool->calls)->toBe(0)
        ->and($gateway->generateCalls)->toBe(1)
        ->and($response->awaitingApproval())->toBeTrue()
        ->and($response->pendingApprovals)->toHaveCount(1)
        ->and($response->pendingApprovals[0]->id)->toBe('call-1')
        ->and($response->toolCalls)->toHaveCount(1)
        ->and($response->toolResults)->toHaveCount(0);
});

test('it resumes approved tool calls and continues generation', function () {
    $tool = new TextGenerationLoopApprovableTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-1');
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
        [new AssistantMessage('', collect([$toolCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ToolApproval::from(['call-1' => true]),
    );

    expect($tool->calls)->toBe(1)
        ->and($tool->handledArguments)->toBe([['value' => 'approved']])
        ->and($gateway->generateCalls)->toBe(1)
        ->and($response->awaitingApproval())->toBeFalse()
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->result)->toBe('handled approved')
        ->and($response->text)->toBe('done');
});

test('it resumes edited tool calls with replacement arguments', function () {
    $tool = new TextGenerationLoopApprovableTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'original'], 'call-1');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$toolCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ToolApproval::from(['call-1' => Approval::edit(['value' => 'edited'])]),
    );

    expect($tool->handledArguments)->toBe([['value' => 'edited']]);
});

test('it records rejection results without executing the tool', function () {
    $tool = new TextGenerationLoopApprovableTool;
    $toolCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'blocked'], 'call-1');
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
        ToolApproval::from(['call-1' => Approval::reject('Not that file')]),
    );

    expect($tool->calls)->toBe(0)
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->result)->toBe('Not that file')
        ->and($gateway->generateCalls)->toBe(1);
});

test('it rejects approval decisions that do not match pending tool calls', function () {
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
        ToolApproval::from(['call-2' => true]),
    ))->toThrow(ApprovalMismatchException::class);
});

test('it emits streamed approval requests without executing gated tools', function () {
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

test('it defers ungated tool calls in a step that also needs approval', function () {
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
        new TextGenerationOptions(maxSteps: 2),
        null,
    );

    expect($gated->calls)->toBe(0)
        ->and($ungated->calls)->toBe(0)
        ->and($response->awaitingApproval())->toBeTrue()
        ->and($response->pendingApprovals)->toHaveCount(1)
        ->and($response->pendingApprovals[0]->id)->toBe('call-gated');
});

test('it resumes a deferred step running both approved and ungated calls', function () {
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
        [new AssistantMessage('', collect([$gatedCall, $ungatedCall]))],
        [$gated, $ungated],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ToolApproval::from(['call-gated' => true]),
    );

    expect($gated->calls)->toBe(1)
        ->and($ungated->calls)->toBe(1)
        ->and($response->awaitingApproval())->toBeFalse()
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->text)->toBe('done');
});

test('a bare rejection stops the loop without another model call', function () {
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
        ToolApproval::from(['call-1' => false]),
    );

    expect($tool->calls)->toBe(0)
        ->and($gateway->generateCalls)->toBe(0)
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->result)->toBe('Tool call rejected by approver.');
});

test('a default decision approves every pending call without naming ids', function () {
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
        [new AssistantMessage('', collect([$gatedCall, $ungatedCall]))],
        [$gated, $ungated],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ToolApproval::approveAll(),
    );

    expect($gated->calls)->toBe(1)
        ->and($ungated->calls)->toBe(1)
        ->and($response->awaitingApproval())->toBeFalse()
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->text)->toBe('done');
});

test('an explicit decision overrides the default decision', function () {
    $tool = new TextGenerationLoopApprovableTool;
    $firstCall = new ToolCall('call-1', TextGenerationLoopApprovableTool::class, ['value' => 'approved'], 'call-1');
    $secondCall = new ToolCall('call-2', TextGenerationLoopApprovableTool::class, ['value' => 'blocked'], 'call-2');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$firstCall, $secondCall]))],
        [$tool],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        new ToolApproval(['call-2' => Approval::reject('Wrong file')], Approval::approve()),
    );

    expect($tool->calls)->toBe(1)
        ->and($tool->handledArguments)->toBe([['value' => 'approved']])
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->toolResults[1]->result)->toBe('Wrong file');
});

test('a default rejection only rejects gated calls and still runs ungated companions', function () {
    $gated = new TextGenerationLoopApprovableTool;
    $ungated = new TextGenerationLoopCountingTool;
    $gatedCall = new ToolCall('call-gated', TextGenerationLoopApprovableTool::class, ['value' => 'blocked'], 'call-gated');
    $ungatedCall = new ToolCall('call-ungated', TextGenerationLoopCountingTool::class, [], 'call-ungated');
    $gateway = new TextGenerationLoopFakeGateway([
        new StepResponse('done', [], FinishReason::Stop, new Usage, new Meta('fake', 'model')),
    ]);

    $response = (new TextGenerationLoop($gateway))->generate(
        textGenerationLoopProvider(),
        'model',
        null,
        [new AssistantMessage('', collect([$gatedCall, $ungatedCall]))],
        [$gated, $ungated],
        null,
        new TextGenerationOptions(maxSteps: 2),
        null,
        ToolApproval::rejectAll('Not now'),
    );

    expect($gated->calls)->toBe(0)
        ->and($ungated->calls)->toBe(1)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->toolResults[0]->result)->toBe('Not now')
        ->and($response->toolResults[1]->result)->toBe('counted');
});

test('a bare default rejection stops the loop without another model call', function () {
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
        ToolApproval::rejectAll(),
    );

    expect($tool->calls)->toBe(0)
        ->and($gateway->generateCalls)->toBe(0)
        ->and($response->toolResults)->toHaveCount(1)
        ->and($response->toolResults[0]->result)->toBe('Tool call rejected by approver.');
});

test('a default decision does not excuse decisions for unknown tool calls', function () {
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
        new ToolApproval(['call-2' => Approval::approve()], Approval::approve()),
    ))->toThrow(ApprovalMismatchException::class);
});

test('a streamed default rejection marks the tool results as unsuccessful', function () {
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
        ToolApproval::rejectAll('Not now'),
    ));

    $toolResults = collect($events)->whereInstanceOf(ToolResultEvent::class);

    expect($tool->calls)->toBe(0)
        ->and($toolResults)->toHaveCount(1)
        ->and($toolResults->first()->successful)->toBeFalse()
        ->and($toolResults->first()->denied)->toBeTrue()
        ->and($toolResults->first()->error)->toBe('Not now')
        ->and(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(1);
});

test('it does not execute tool calls on the final generation step', function () {
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
        ->and($response->steps)->toHaveCount(1)
        ->and($response->steps->first()->toolResults)->toHaveCount(1);
});

test('it holds stream end until the streamed tool loop is complete', function () {
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

test('it does not execute streamed tool calls on the final step', function () {
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
        ->and(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(1);
});

test('it clamps non-positive maxSteps to at least one turn', function (int $maxSteps) {
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

test('it accumulates streamed usage across multi-step turns', function () {
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

test('it throws when generation tool calls do not match local tools', function () {
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
        null,
        null,
    ))->toThrow(NoSuchToolException::class, "Model tried to call unavailable tool 'MissingTool'.");
});

test('it throws when streaming tool calls do not match local tools', function () {
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

test('it emits a terminal stream end when a turn yields no stream end or error', function () {
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(events: [new TextDelta('text-delta', 'message-1', 'partial', time())]),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        null,
        null,
    ));

    $streamEnds = collect($events)->whereInstanceOf(StreamEnd::class);

    expect($streamEnds)->toHaveCount(1)
        ->and($streamEnds->first()->reason)->toBe(FinishReason::Error->value);
});

test('it does not emit a stream end when a turn errors without a stream end', function () {
    $gateway = new TextGenerationLoopFakeGateway(streams: [
        textGenerationLoopStreamStep(events: [new Error('error-1', 'server_error', 'Server overloaded', false, time())]),
    ]);

    $events = iterator_to_array((new TextGenerationLoop($gateway))->stream(
        'invocation-1',
        textGenerationLoopProvider(),
        'model',
        null,
        [],
        [],
        null,
        null,
        null,
    ));

    expect(collect($events)->whereInstanceOf(StreamEnd::class))->toHaveCount(0)
        ->and(collect($events)->whereInstanceOf(Error::class))->toHaveCount(1);
});

function textGenerationLoopProvider(): TextProvider
{
    $provider = Mockery::mock(TextProvider::class);
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
    use InteractsWithApproval;

    public array $handledArguments = [];

    protected function needsApproval(Request $request): Approval
    {
        return Approval::required('Needs a human');
    }

    public function handle(Request $request): string
    {
        $this->calls++;
        $this->handledArguments[] = $request->all();

        return 'handled '.$request['value'];
    }
}
