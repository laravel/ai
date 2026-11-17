<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Laravel\Ai\Tools\ToolResponse;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;
use Tests\Fixtures\Tools\FileReturningTool;
use Tests\Fixtures\Tools\FixedNumberGenerator;

function bedrockToolCallResponse(string $toolUseId): array
{
    return [
        'output' => ['message' => ['content' => [
            ['toolUse' => ['toolUseId' => $toolUseId, 'name' => 'FixedNumberGenerator', 'input' => []]],
        ]]],
        'usage' => ['inputTokens' => 7, 'outputTokens' => 3],
        'stopReason' => 'tool_use',
    ];
}

function bedrockTextResponse(string $text): array
{
    return [
        'output' => ['message' => ['content' => [['text' => $text]]]],
        'usage' => ['inputTokens' => 7, 'outputTokens' => 5],
        'stopReason' => 'end_turn',
    ];
}

describe('tool call loop', function (): void {
    test('multi step tool loop returns accumulated response shape', function (): void {
        $client = $this->fakeBedrockConverseSequence([
            bedrockToolCallResponse('t1'),
            bedrockToolCallResponse('t2'),
            bedrockTextResponse('Done'),
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = (new TextGenerationLoop($gateway))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            messages: [new UserMessage('Generate numbers')],
            tools: [new FixedNumberGenerator],
            options: new TextGenerationOptions(maxSteps: 5),
        );

        expect((string) $response)->toBe('Done')
            ->and($response->messages)->toHaveCount(5)
            ->and($response->steps)->toHaveCount(3)
            ->and($response->toolCalls)->toHaveCount(2)
            ->and($response->toolResults)->toHaveCount(2)
            ->and($response->usage->inputTokens)->toBe(21)
            ->and($response->usage->outputTokens)->toBe(11);
    });

    test('max steps limits tool call depth', function (): void {
        $client = $this->fakeBedrockConverseSequence([
            bedrockToolCallResponse('t1'),
            bedrockToolCallResponse('t2'),
            bedrockToolCallResponse('t3'),
            bedrockTextResponse('Done'),
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = (new TextGenerationLoop($gateway))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            messages: [new UserMessage('Generate numbers')],
            tools: [new FixedNumberGenerator],
            options: new TextGenerationOptions(maxSteps: 2),
        );

        expect($response->steps)->toHaveCount(2);
    });

    test('unknown tool call throws NoSuchToolException', function (): void {
        $client = $this->fakeBedrockConverse([
            'output' => ['message' => ['content' => [
                ['toolUse' => ['toolUseId' => 't1', 'name' => 'NonExistentTool', 'input' => []]],
            ]]],
            'usage' => ['inputTokens' => 7, 'outputTokens' => 3],
            'stopReason' => 'tool_use',
        ]);

        $gateway = $this->gatewayWithClient($client);

        expect(fn (): TextResponse => (new TextGenerationLoop($gateway))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            tools: [new FixedNumberGenerator],
        ))->toThrow(NoSuchToolException::class);
    });

    test('structured output is parsed from the synthetic tool call', function (): void {
        $requests = [];

        $client = $this->fakeBedrockConverseSequence([[
            'output' => ['message' => ['content' => [
                ['toolUse' => ['toolUseId' => 's1', 'name' => 'structured_output', 'input' => ['symbol' => 'Fe']]],
            ]]],
            'usage' => ['inputTokens' => 8, 'outputTokens' => 4],
            'stopReason' => 'tool_use',
        ]], $requests);

        $gateway = $this->gatewayWithClient($client);

        $response = (new TextGenerationLoop($gateway))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            schema: ['symbol' => (new JsonSchemaTypeFactory)->string()],
        );

        expect($response)->toBeInstanceOf(StructuredTextResponse::class)
            ->and($response->structured)->toMatchArray(['symbol' => 'Fe'])
            ->and($response->steps)->toHaveCount(1)
            ->and($response->usage->inputTokens)->toBe(8)
            ->and($response->usage->outputTokens)->toBe(4)
            ->and($requests[0]['toolConfig']['toolChoice'])->toBe(['tool' => ['name' => 'structured_output']])
            ->and($requests[0])->not->toHaveKey('system');
    });

    test('structured output is parsed from the json answer of models that reject forced tool choice', function (): void {
        $requests = [];

        $client = $this->fakeBedrockConverseSequence([
            bedrockTextResponse('{"symbol": "Fe"}'),
        ], $requests);

        $response = (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
            $this->bedrockProvider(),
            'us.anthropic.claude-sonnet-5-5',
            'You are a helpful assistant.',
            schema: ['symbol' => (new JsonSchemaTypeFactory)->string()],
        );

        expect($response)->toBeInstanceOf(StructuredTextResponse::class)
            ->and($response->structured)->toBe(['symbol' => 'Fe'])
            ->and($requests[0])->not->toHaveKey('toolConfig')
            ->and($requests[0]['system'])->toHaveCount(2)
            ->and($requests[0]['system'][0])->toBe(['text' => 'You are a helpful assistant.'])
            ->and($requests[0]['system'][1]['text'])->toContain('"symbol"');
    });

    test('models that reject forced tool choice keep real tools on auto selection before the json answer', function (): void {
        $requests = [];

        $client = $this->fakeBedrockConverseSequence([
            bedrockToolCallResponse('t1'),
            bedrockTextResponse('{"number": 42}'),
        ], $requests);

        $response = (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
            $this->bedrockProvider(),
            'us.anthropic.claude-opus-5-5',
            null,
            messages: [new UserMessage('Generate a number')],
            tools: [new FixedNumberGenerator],
            schema: ['number' => (new JsonSchemaTypeFactory)->integer()],
            options: new TextGenerationOptions(maxSteps: 2),
        );

        expect($response->structured)->toBe(['number' => 42])
            ->and($response->toolResults)->toHaveCount(1)
            ->and($requests)->toHaveCount(2);

        foreach ($requests as $request) {
            expect($request['toolConfig'])->not->toHaveKey('toolChoice')
                ->and(array_column(array_column($request['toolConfig']['tools'], 'toolSpec'), 'name'))->toBe(['FixedNumberGenerator']);
        }
    });

    test('structured runs paused for tool approval keep the structured response shape', function (): void {
        $client = $this->fakeBedrockConverseSequence([[
            'output' => ['message' => ['content' => [
                ['toolUse' => ['toolUseId' => 't1', 'name' => 'ApprovableNumberGenerator', 'input' => []]],
            ]]],
            'usage' => ['inputTokens' => 7, 'outputTokens' => 3],
            'stopReason' => 'tool_use',
        ]]);

        $response = (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            messages: [new UserMessage('Generate a number')],
            tools: [new ApprovableNumberGenerator],
            schema: ['number' => (new JsonSchemaTypeFactory)->integer()],
            options: new TextGenerationOptions(maxSteps: 2),
        );

        expect($response)->toBeInstanceOf(StructuredTextResponse::class)
            ->and($response->structured)->toBe([])
            ->and($response->pendingApprovals)->toHaveCount(1)
            ->and($response->pendingApprovals[0]->id)->toBe('t1');
    });

    test('streaming tool loop emits a single stream end with accumulated usage', function (): void {
        $client = $this->fakeBedrockStreamSequence([
            [
                $this->contentBlockStart(0, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(0, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(0),
                $this->messageStop('tool_use'),
                ['metadata' => ['usage' => ['inputTokens' => 5, 'outputTokens' => 2]]],
            ],
            [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
                ['metadata' => ['usage' => ['inputTokens' => 5, 'outputTokens' => 2]]],
            ],
        ]);

        $gateway = $this->gatewayWithClient($client);

        $events = iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-opus-4-7-v1:0',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $streamEnds = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd));
        $toolResults = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolResultEvent));

        expect($streamEnds)->toHaveCount(1)
            ->and($streamEnds[0]->reason)->toBe('stop')
            ->and($streamEnds[0]->usage->inputTokens)->toBe(10)
            ->and($streamEnds[0]->usage->outputTokens)->toBe(4)
            ->and($toolResults)->toHaveCount(1)
            ->and($events[count($events) - 1])->toBeInstanceOf(StreamEnd::class);
    });
});

test('files a tool returns are sent inside its tool result', function (): void {
    $requests = [];
    $client = $this->fakeBedrockConverseSequence([
        bedrockToolCallResponse('t1'),
        bedrockTextResponse('The chart is red.'),
    ], $requests);

    (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
        $this->bedrockProvider(),
        'anthropic.claude-opus-4-7-v1:0',
        null,
        messages: [new UserMessage('Describe the sales chart')],
        tools: [new FileReturningTool(ToolResponse::make('Here is the chart.', [
            Image::fromPath(__DIR__.'/../../../Fixtures/Images/red.png'),
            Document::fromString('%PDF-1.4 sales', 'application/pdf')->as('report.pdf'),
        ]), 'FixedNumberGenerator')],
        options: new TextGenerationOptions(maxSteps: 5),
    );

    $content = $requests[1]['messages'][2]['content'][0]['toolResult']['content'];

    expect($content)->toHaveCount(3)
        ->and($content[0])->toBe(['text' => 'Here is the chart.'])
        ->and($content[1]['image']['format'])->toBe('png')
        ->and($content[2]['document'])->toMatchArray(['format' => 'pdf']);
});

test('image tool results are noted for models that cannot take them', function (): void {
    $requests = [];
    $client = $this->fakeBedrockConverseSequence([
        bedrockToolCallResponse('t1'),
        bedrockTextResponse('Done.'),
    ], $requests);

    (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
        $this->bedrockProvider(),
        'meta.llama4-maverick-17b-instruct-v1:0',
        null,
        messages: [new UserMessage('Describe the sales chart')],
        tools: [new FileReturningTool(ToolResponse::make('Here is the chart.', [
            Image::fromPath(__DIR__.'/../../../Fixtures/Images/red.png'),
            Document::fromString('%PDF-1.4 sales', 'application/pdf')->as('report.pdf'),
        ]), 'FixedNumberGenerator')],
        options: new TextGenerationOptions(maxSteps: 5),
    );

    $content = $requests[1]['messages'][2]['content'][0]['toolResult']['content'];

    expect($content)->toHaveCount(2)
        ->and($content[0])->toBe(['text' => "Here is the chart.\n(The tool returned the image [red.png], but it could not be sent to this model.)"])
        ->and($content[1]['document'])->toMatchArray(['format' => 'pdf']);
});

test('a document format bedrock cannot take is noted in its tool result', function (): void {
    $requests = [];
    $client = $this->fakeBedrockConverseSequence([
        bedrockToolCallResponse('t1'),
        bedrockTextResponse('Done.'),
    ], $requests);

    (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
        $this->bedrockProvider(),
        'anthropic.claude-opus-4-7-v1:0',
        null,
        messages: [new UserMessage('Unpack the archive')],
        tools: [new FileReturningTool(ToolResponse::make('Here is the archive.', [
            Document::fromString("PK\0\0", 'application/zip')->as('archive.zip'),
        ]), 'FixedNumberGenerator')],
        options: new TextGenerationOptions(maxSteps: 5),
    );

    expect($requests[1]['messages'][2]['content'][0]['toolResult']['content'])->toBe([
        ['text' => "Here is the archive.\n(The tool returned the document [archive.zip], but it could not be sent to this model.)"],
    ]);
});

test('a remote document is noted in its tool result without being fetched', function (): void {
    Http::fake(['example.com/*' => Http::response('Not found', 404)]);
    $requests = [];
    $client = $this->fakeBedrockConverseSequence([
        bedrockToolCallResponse('t1'),
        bedrockTextResponse('Done.'),
    ], $requests);

    (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
        $this->bedrockProvider(),
        'anthropic.claude-opus-4-7-v1:0',
        null,
        messages: [new UserMessage('Read the report')],
        tools: [new FileReturningTool(ToolResponse::make('Here is the report.', [
            Document::fromUrl('https://example.com/report')->as('report'),
        ]), 'FixedNumberGenerator')],
        options: new TextGenerationOptions(maxSteps: 5),
    );

    expect($requests[1]['messages'][2]['content'][0]['toolResult']['content'])->toBe([
        ['text' => "Here is the report.\n(The tool returned the document [report], but it could not be sent to this model.)"],
    ]);
    Http::assertNothingSent();
});
