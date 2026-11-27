<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Providers\OpenResponsesProvider;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Tests\Fixtures\Agents\LabProviderOptionsAgent;
use Tests\Fixtures\Agents\NestedStructuredAgent;
use Tests\Fixtures\Agents\StructuredAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

use function Laravel\Ai\agent;

beforeEach(function (): void {
    config(['ai.providers.open-responses' => [
        'driver' => 'open-responses',
        'url' => 'http://localhost:8080/v1/',
        'key' => 'test-key',
        'models' => ['text' => ['default' => 'local-model']],
    ]]);
});

function openResponsesBody(array $output, array $overrides = []): array
{
    return [
        'id' => 'resp_1',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'local-model',
        'output' => $output,
        'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
        ...$overrides,
    ];
}

function openResponsesText(string $text): array
{
    return openResponsesBody([[
        'type' => 'message',
        'id' => 'msg_1',
        'status' => 'completed',
        'role' => 'assistant',
        'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]],
    ]]);
}

function openResponsesStream(array $events): string
{
    return implode('', array_map(fn (array $event): string => 'data: '.json_encode($event)."\n\n", $events));
}

test('the provider resolves from the open-responses driver', function (): void {
    expect(Lab::OpenResponses->value)->toBe('open-responses')
        ->and(app('Laravel\Ai\AiManager')->textProvider('open-responses'))->toBeInstanceOf(OpenResponsesProvider::class);
});

test('the shipped config registers the provider', function (): void {
    $config = require __DIR__.'/../../../../config/ai.php';

    expect($config['providers']['open-responses'])->toMatchArray(['driver' => 'open-responses'])
        ->toHaveKeys(['url', 'key']);
});

test('text requests post to the configured responses endpoint with bearer auth', function (): void {
    Http::fake(['*' => Http::response(openResponsesText('Hello there'))]);

    $response = agent()->prompt('Hello', provider: 'open-responses');

    expect($response->text)->toBe('Hello there')
        ->and($response->meta->provider)->toBe('open-responses')
        ->and($response->meta->model)->toBe('local-model');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8080/v1/responses'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request['model'] === 'local-model'
        && $request['store'] === false
        && ! isset($request['include']));
});

test('the authorization header is omitted without a key', function (?string $key): void {
    config(['ai.providers.open-responses.key' => $key]);

    Http::fake(['*' => Http::response(openResponsesText('Hi'))]);

    agent()->prompt('Hello', provider: 'open-responses');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
})->with(['null' => [null], 'blank' => ['']]);

test('a url is required', function (?string $url): void {
    config(['ai.providers.open-responses.url' => $url]);

    expect(fn (): AgentResponse => agent()->prompt('Hello', provider: 'open-responses'))
        ->toThrow(InvalidArgumentException::class, "requires a 'url'");
})->with(['null' => [null], 'blank' => ['  ']]);

test('a default model is required unless one is passed', function (): void {
    config(['ai.providers.open-responses.models' => []]);

    expect(fn (): AgentResponse => agent()->prompt('Hello', provider: 'open-responses'))
        ->toThrow(InvalidArgumentException::class, 'requires a default text model');

    Http::fake(['*' => Http::response(openResponsesText('Hi'))]);

    agent()->prompt('Hello', provider: 'open-responses', model: 'explicit-model');

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'explicit-model');
});

test('the cheapest and smartest models fall back to the default', function (): void {
    $provider = app('Laravel\Ai\AiManager')->textProvider('open-responses');

    expect($provider->cheapestTextModel())->toBe('local-model')
        ->and($provider->smartestTextModel())->toBe('local-model');
});

test('storage is opt in through the store option', function (): void {
    config(['ai.providers.open-responses.store' => true]);

    Http::fake(['*' => Http::response(openResponsesText('Hi'))]);

    agent()->prompt('Hello', provider: 'open-responses');

    Http::assertSent(fn (Request $request): bool => ! isset($request['store']));
});

test('provider options resolve by the configured provider name', function (): void {
    config(['ai.providers.my-server' => config('ai.providers.open-responses')]);

    Http::fake(['*' => Http::response(openResponsesText('Hi'))]);

    (new LabProviderOptionsAgent([
        'my-server' => ['top_logprobs' => 2],
        'open-responses' => ['ignored' => true],
    ]))->prompt('Hello', provider: 'my-server');

    Http::assertSent(fn (Request $request): bool => $request['top_logprobs'] === 2 && ! isset($request['ignored']));
});

test('a failed response throws an Open Responses error', function (): void {
    Http::fake(['*' => Http::response(openResponsesBody([], [
        'status' => 'failed',
        'error' => ['type' => 'server_error', 'message' => 'Model crashed.'],
    ]))]);

    expect(fn (): AgentResponse => agent()->prompt('Hello', provider: 'open-responses'))
        ->toThrow(AiException::class, 'Open Responses Error: [server_error] Model crashed.');
});

test('text across messages and trailing extension items is parsed', function (): void {
    Http::fake(['*' => Http::response(openResponsesBody([
        ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'One']]],
        ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => ' two']]],
        ['type' => 'acme:trace', 'id' => 'tr_1'],
    ]))]);

    $response = agent()->prompt('Hello', provider: 'open-responses');

    expect($response->text)->toBe('One two')
        ->and($response->steps->last()->finishReason->value)->toBe('stop');
});

test('a stream error without a code reports its type', function (): void {
    Http::fake(['*' => Http::response(openResponsesStream([
        ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'model' => 'local-model']],
        ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Bad input.']],
    ]), 200, ['Content-Type' => 'text/event-stream'])]);

    $events = [];

    try {
        foreach (agent()->stream('Hello', provider: 'open-responses') as $event) {
            $events[] = $event;
        }
    } catch (StreamErrorException) {
        //
    }

    $error = collect($events)->first(fn ($event): bool => $event instanceof Error);

    expect($error)->not->toBeNull()
        ->and($error->type)->toBe('invalid_request_error')
        ->and($error->message)->toBe('Bad input.');
});

test('streamed reasoning without deltas is kept from the completed response', function (): void {
    Http::fake(['*' => Http::response(openResponsesStream([
        ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'model' => 'local-model']],
        ['type' => 'response.output_text.delta', 'delta' => 'Answer'],
        ['type' => 'response.output_text.done', 'text' => 'Answer'],
        ['type' => 'response.completed', 'response' => openResponsesBody([
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'content' => [['type' => 'reasoning_text', 'text' => 'Buffered thought']]],
            ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Answer']]],
        ])],
    ]), 200, ['Content-Type' => 'text/event-stream'])]);

    $stream = agent()->stream('Hello', provider: 'open-responses');

    foreach ($stream as $event) {
        //
    }

    $end = $stream->events->whereInstanceOf(StreamEnd::class)->last();

    expect($stream->text)->toBe('Answer')
        ->and($end->steps->last()->reasoning)->toBe('Buffered thought');
});

test('multi-step tool loops are stateless by default and replay encrypted reasoning', function (): void {
    Http::fake([
        '*' => Http::sequence([
            Http::response(openResponsesBody([
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc_blob_1'],
                ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}', 'status' => 'completed'],
            ])),
            Http::response(openResponsesText('The number is 72019')),
        ]),
    ]);

    $response = (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'open-responses');

    expect($response->text)->toBe('The number is 72019');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(2);

    $followUp = json_decode((string) $recorded[1][0]->body(), true);

    expect($followUp)->not->toHaveKey('previous_response_id')
        ->and($followUp['store'] ?? null)->toBeFalse();

    $input = collect($followUp['input']);

    expect($input->contains(fn ($item): bool => ($item['type'] ?? null) === 'reasoning'
        && ($item['encrypted_content'] ?? null) === 'enc_blob_1'))->toBeTrue()
        ->and($input->contains(fn ($item): bool => ($item['type'] ?? null) === 'function_call_output'
            && ($item['call_id'] ?? null) === 'call_1'))->toBeTrue();
});

test('opt-in store true uses previous_response_id in multi-step tool loops', function (): void {
    config(['ai.providers.open-responses.store' => true]);

    Http::fake([
        '*' => Http::sequence([
            Http::response(openResponsesBody([
                ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}', 'status' => 'completed'],
            ])),
            Http::response(openResponsesText('Done')),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'open-responses');

    $followUp = json_decode((string) Http::recorded()[1][0]->body(), true);

    expect($followUp['previous_response_id'] ?? null)->toBe('resp_1')
        ->and($followUp)->not->toHaveKey('store');
});

test('structured output defaults to strict json_schema text format', function (): void {
    Http::fake(['*' => Http::response(openResponsesText('{"symbol":"Au"}'))]);

    (new StructuredAgent)->prompt('Gold?', provider: 'open-responses');

    Http::assertSent(function (Request $request): bool {
        $format = data_get(json_decode($request->body(), true), 'text.format');

        return ($format['type'] ?? null) === 'json_schema'
            && ($format['strict'] ?? null) === true;
    });
});

test('structured output without Strict attribute sends strict false', function (): void {
    Http::fake(['*' => Http::response(openResponsesText('{"elements":[]}'))]);

    (new NestedStructuredAgent)->prompt('Elements?', provider: 'open-responses');

    Http::assertSent(function (Request $request): bool {
        $format = data_get(json_decode($request->body(), true), 'text.format');

        return ($format['type'] ?? null) === 'json_schema'
            && ($format['strict'] ?? null) === false;
    });
});

test('unsupported provider tools are stripped and tool search is unwrapped to plain functions', function (): void {
    Http::fake(['*' => Http::response(openResponsesText('Done'))]);

    agent(tools: [
        new WebSearch,
        new ToolSearch([new FixedNumberGenerator]),
    ])->prompt('Hello', provider: 'open-responses');

    $body = json_decode((string) Http::recorded()[0][0]->body(), true);

    expect($body['tools'])->toHaveCount(1)
        ->and($body['tools'][0]['type'])->toBe('function')
        ->and($body['tools'][0]['name'])->toBe('FixedNumberGenerator');
});
