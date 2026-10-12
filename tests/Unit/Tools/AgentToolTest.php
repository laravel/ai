<?php

use JMac\Testing\Double;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;

test('an agent tool streams its events by default and returns its final text', function (): void {
    $stream = new StreamableAgentResponse('invocation-sub', fn (): Generator => yield from [
        new TextDelta('event-1', 'message-1', 'sub answer', time()),
        new StreamEnd('event-2', 'stop', new TextUsage, time()),
    ], new Meta('fake', 'model'));

    $agent = Double::for(Agent::class);
    $agent->expects('stream')->returns($stream);
    $agent->expects('prompt')->never();

    $generator = (new AgentTool($agent))->stream(new Request(['task' => 'Do the thing']));

    $events = iterator_to_array($generator);

    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(TextDelta::class)
        ->and($generator->getReturn())->toBe('sub answer');
});

test('a structured sub-agent answers through a regular prompt on the streaming path', function (): void {
    $agent = Double::for(Agent::class, HasStructuredOutput::class);
    $agent->expects('stream')->never();
    $agent->expects('prompt')->returns(new StructuredAgentResponse(
        'invocation-sub', ['answer' => 42], '{"answer":42}', new TextUsage, new Meta('fake', 'model')
    ));

    $generator = (new AgentTool($agent))->stream(new Request(['task' => 'Do the thing']));

    expect(iterator_to_array($generator))->toBe([])
        ->and($generator->getReturn())->toBe('{"answer":42}');
});

test('a failing sub-agent surfaces its error as the tool result on the streaming path', function (): void {
    $agent = Double::for(Agent::class);
    $agent->expects('stream')->throws(new RuntimeException('provider exploded'));

    $generator = (new AgentTool($agent))->stream(new Request(['task' => 'Do the thing']));

    expect(iterator_to_array($generator))->toBe([])
        ->and($generator->getReturn())->toBe('Agent failed: provider exploded');
});
