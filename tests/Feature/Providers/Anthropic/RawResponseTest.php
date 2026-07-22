<?php

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;

test('text responses expose the raw http response', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('Hello there'),
    ]);

    $response = (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    expect($response->getRaw())->toBeInstanceOf(Response::class)
        ->and($response->getRaw()->json('content.0.text'))->toBe('Hello there');
});

test('structured responses expose the raw http response', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeStructuredResponse(['name' => 'Taylor', 'age' => 30]),
    ]);

    $response = (new StructuredAgent)->prompt(
        'Tell me about Taylor',
        provider: 'anthropic',
    );

    expect($response->getRaw())->toBeInstanceOf(Response::class)
        ->and($response->getRaw()->json('id'))->toBe('msg_123');
});

test('tool call loops expose the raw http response of the final step', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    expect($response->getRaw())->toBeInstanceOf(Response::class)
        ->and($response->getRaw()->json('content.0.text'))->toBe('The number is 72019');
});

test('each step exposes the raw http response of its own request', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    expect($response->steps)->toHaveCount(2)
        ->and($response->steps[0]->getRaw())->toBeInstanceOf(Response::class)
        ->and($response->steps[0]->getRaw()->json('stop_reason'))->toBe('tool_use')
        ->and($response->steps[1]->getRaw())->toBeInstanceOf(Response::class)
        ->and($response->steps[1]->getRaw()->json('content.0.text'))->toBe('The number is 72019');
});

test('agent prompted event exposes the raw http response', function (): void {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('Hello there'),
    ]);

    $raw = null;

    Event::listen(AgentPrompted::class, function (AgentPrompted $event) use (&$raw): void {
        $raw = $event->response->getRaw();
    });

    (new AssistantAgent)->prompt(
        'Hi',
        provider: 'anthropic',
    );

    expect($raw)->toBeInstanceOf(Response::class)
        ->and($raw->json('content.0.text'))->toBe('Hello there');
});

test('agent streamed event listeners may safely call get raw', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            body: $this->ssePayload([
                $this->messageStart(),
                $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                $this->contentBlockStop(0),
                $this->messageDelta('end_turn', 10),
            ]),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        ),
    ]);

    $invoked = false;

    Event::listen(AgentStreamed::class, function (AgentStreamed $event) use (&$invoked): void {
        $invoked = true;

        expect($event->response->getRaw())->toBeNull();
    });

    $this->collectStreamEvents();

    expect($invoked)->toBeTrue();
});

test('responses discard the raw http response when serialized', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence([
            $this->fakeUniqueToolCallResponse(),
            $this->fakeTextResponse('The number is 72019'),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a random number',
        provider: 'anthropic',
    );

    $restored = unserialize(serialize($response));

    expect($restored->getRaw())->toBeNull()
        ->and($restored->text)->toBe('The number is 72019')
        ->and($restored->steps)->toHaveCount(2)
        ->and($restored->steps[1]->getRaw())->toBeNull()
        ->and($restored->steps[1]->text)->toBe('The number is 72019');
});
