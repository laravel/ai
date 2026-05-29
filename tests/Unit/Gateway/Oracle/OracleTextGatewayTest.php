<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Oracle\OracleTextGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Agents\ProviderOptionsAgent;

function oracleGateway(): object
{
    return new class extends OracleTextGateway
    {
        public function callIsCohereModel(string $model): bool
        {
            return $this->isCohereModel($model);
        }

        public function callApiFormat(string $model): string
        {
            return $this->apiFormat($model);
        }

        public function callBuildGenericMessages(array $messages, ?string $instructions): array
        {
            return $this->buildGenericMessages($messages, $instructions);
        }

        public function callBuildCohereState(array $messages, ?string $instructions): array
        {
            return $this->buildCohereState($messages, $instructions);
        }

        public function callFormatGenericTools(array $tools): array
        {
            return $this->formatGenericTools($tools);
        }

        public function callFormatCohereTools(array $tools): array
        {
            return $this->formatCohereTools($tools);
        }

        public function callBuildGenericChatRequest(array $messages, ?array $schemaTools, ?array $formattedTools, bool $toolsEmpty, ?TextGenerationOptions $options, bool $isFinalStep, bool $isStream = false): array
        {
            return $this->buildGenericChatRequest($messages, $schemaTools, $formattedTools, $toolsEmpty, $options, $isFinalStep, $isStream);
        }

        public function callBuildCohereChatRequest(array $state, ?array $formattedTools, ?array $schemaTool, ?TextGenerationOptions $options, array $toolResults = [], bool $isStream = false): array
        {
            return $this->buildCohereChatRequest($state, $formattedTools, $schemaTool, $options, $toolResults, $isStream);
        }

        public function callParseGenericResponse(array $chatResponse): array
        {
            return $this->parseGenericResponse($chatResponse);
        }

        public function callParseCohereResponse(array $chatResponse): array
        {
            return $this->parseCohereResponse($chatResponse);
        }

        public function callGenericFinishReason(string $reason): FinishReason
        {
            return $this->genericFinishReason($reason);
        }

        public function callCohereFinishReason(string $reason): FinishReason
        {
            return $this->cohereFinishReason($reason);
        }

        public function callSchemaToParameterDefinitions(array $jsonSchema): array
        {
            return $this->schemaToParameterDefinitions($jsonSchema);
        }

        public function callBuildGenericSchemaTools(array $schema, array $tools): array
        {
            return $this->buildGenericSchemaTools($schema, $tools);
        }

        public function callBuildCohereSchemaTool(array $schema): array
        {
            return $this->buildCohereSchemaTool($schema);
        }

        public function callResolveMaxSteps(array $tools, ?TextGenerationOptions $options): int
        {
            return $this->resolveMaxSteps($tools, $options);
        }

        public function callStreamTextDelta(array $event): string
        {
            return $this->streamTextDelta($event);
        }
    };
}

class OracleSampleTool implements Tool
{
    public function description(): string
    {
        return 'Sample description';
    }

    public function handle(Request $request): string
    {
        return 'ok';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['city' => $schema->string()->description('The city')->required()];
    }
}

test('cohere models are detected and mapped to the COHERE api format', function () {
    $gateway = oracleGateway();

    expect($gateway->callIsCohereModel('cohere.command-a-03-2025'))->toBeTrue()
        ->and($gateway->callApiFormat('cohere.command-a-03-2025'))->toBe('COHERE')
        ->and($gateway->callIsCohereModel('meta.llama-3.3-70b-instruct'))->toBeFalse()
        ->and($gateway->callApiFormat('meta.llama-3.3-70b-instruct'))->toBe('GENERIC')
        ->and($gateway->callApiFormat('xai.grok-3'))->toBe('GENERIC');
});

test('generic messages map system instructions and roles into content parts', function () {
    $messages = oracleGateway()->callBuildGenericMessages([
        new UserMessage('Hello'),
        new AssistantMessage('Hi there'),
    ], 'You are helpful');

    expect($messages)->toEqual([
        ['role' => 'SYSTEM', 'content' => [['type' => 'TEXT', 'text' => 'You are helpful']]],
        ['role' => 'USER', 'content' => [['type' => 'TEXT', 'text' => 'Hello']]],
        ['role' => 'ASSISTANT', 'content' => [['type' => 'TEXT', 'text' => 'Hi there']]],
    ]);
});

test('cohere state extracts the latest user turn as message and the rest as history', function () {
    $state = oracleGateway()->callBuildCohereState([
        new UserMessage('first question'),
        new AssistantMessage('an answer'),
        new UserMessage('second question'),
    ], 'You are helpful');

    expect($state['message'])->toBe('second question')
        ->and($state['preamble'])->toBe('You are helpful')
        ->and($state['chatHistory'])->toEqual([
            ['role' => 'USER', 'message' => 'first question'],
            ['role' => 'CHATBOT', 'message' => 'an answer'],
        ]);
});

test('format generic tools produces function tool specs', function () {
    $tools = oracleGateway()->callFormatGenericTools([new OracleSampleTool]);

    expect($tools)->toHaveCount(1)
        ->and($tools[0]['type'])->toBe('FUNCTION')
        ->and($tools[0]['function']['name'])->toBe('OracleSampleTool')
        ->and($tools[0]['function']['description'])->toBe('Sample description')
        ->and($tools[0]['function']['parameters'])->toBeArray();
});

test('format cohere tools produces parameter definitions', function () {
    $tools = oracleGateway()->callFormatCohereTools([new OracleSampleTool]);

    expect($tools)->toHaveCount(1)
        ->and($tools[0]['name'])->toBe('OracleSampleTool')
        ->and($tools[0]['parameterDefinitions']['city'])->toEqual([
            'description' => 'The city',
            'type' => 'string',
            'isRequired' => true,
        ]);
});

test('generic chat request omits tool fields when no tools or schema present', function () {
    $request = oracleGateway()->callBuildGenericChatRequest(
        [['role' => 'USER', 'content' => [['type' => 'TEXT', 'text' => 'hi']]]],
        null, null, true, null, false,
    );

    expect($request['apiFormat'])->toBe('GENERIC')
        ->and($request)->not->toHaveKey('tools')
        ->and($request)->not->toHaveKey('toolChoice')
        ->and($request)->not->toHaveKey('isStream');
});

test('generic chat request forces the structured tool on the final schema step', function () {
    $schemaTools = oracleGateway()->callBuildGenericSchemaTools([], []);

    $request = oracleGateway()->callBuildGenericChatRequest([], $schemaTools, null, true, null, true);

    expect($request['tools'])->toBe($schemaTools)
        ->and($request['toolChoice'])->toEqual(['type' => 'FUNCTION', 'function' => ['name' => 'structured_output']]);
});

test('generic chat request uses auto tool choice on non-final schema steps', function () {
    $schemaTools = oracleGateway()->callBuildGenericSchemaTools([], [new OracleSampleTool]);

    $request = oracleGateway()->callBuildGenericChatRequest([], $schemaTools, null, false, null, false);

    expect($request['toolChoice'])->toBe('AUTO');
});

test('cohere chat request maps message, preamble, history, and force single step for schemas', function () {
    $schemaTool = oracleGateway()->callBuildCohereSchemaTool([]);

    $request = oracleGateway()->callBuildCohereChatRequest(
        ['message' => 'hi', 'chatHistory' => [['role' => 'USER', 'message' => 'earlier']], 'preamble' => 'be nice'],
        null, $schemaTool, null,
    );

    expect($request['apiFormat'])->toBe('COHERE')
        ->and($request['message'])->toBe('hi')
        ->and($request['preambleOverride'])->toBe('be nice')
        ->and($request['chatHistory'])->toEqual([['role' => 'USER', 'message' => 'earlier']])
        ->and($request['tools'])->toBe($schemaTool)
        ->and($request['isForceSingleStep'])->toBeTrue();
});

test('cohere chat request attaches tool results when present', function () {
    $request = oracleGateway()->callBuildCohereChatRequest(
        ['message' => 'hi', 'chatHistory' => [], 'preamble' => null],
        null, null, null,
        [['call' => ['name' => 'X', 'parameters' => []], 'outputs' => [['output' => 'done']]]],
    );

    expect($request['toolResults'])->toEqual([
        ['call' => ['name' => 'X', 'parameters' => []], 'outputs' => [['output' => 'done']]],
    ]);
});

test('chat requests flat-merge agent provider options for oracle', function () {
    $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

    $request = oracleGateway()->callBuildGenericChatRequest(
        [['role' => 'USER', 'content' => [['type' => 'TEXT', 'text' => 'hi']]]],
        null, null, true, $options, false,
    );

    expect($request['frequencyPenalty'])->toBe(0.5)
        ->and($request['presencePenalty'])->toBe(0.3);
});

test('parse generic response extracts text, tool calls, finish reason, and usage', function () {
    $parsed = oracleGateway()->callParseGenericResponse([
        'choices' => [[
            'message' => [
                'content' => [['type' => 'TEXT', 'text' => 'Hello world']],
                'toolCalls' => [['id' => 'call_1', 'name' => 'X', 'arguments' => '{"a":1}']],
            ],
            'finishReason' => 'tool_calls',
        ]],
        'usage' => ['promptTokens' => 12, 'completionTokens' => 8],
    ]);

    expect($parsed['text'])->toBe('Hello world')
        ->and($parsed['toolCalls'][0]->id)->toBe('call_1')
        ->and($parsed['toolCalls'][0]->name)->toBe('X')
        ->and($parsed['toolCalls'][0]->arguments)->toEqual(['a' => 1])
        ->and($parsed['finishReason'])->toBe(FinishReason::ToolCalls)
        ->and($parsed['usage']->promptTokens)->toBe(12)
        ->and($parsed['usage']->completionTokens)->toBe(8);
});

test('parse cohere response extracts text, tool calls, finish reason, and usage', function () {
    $parsed = oracleGateway()->callParseCohereResponse([
        'text' => 'The answer',
        'finishReason' => 'COMPLETE',
        'toolCalls' => [['name' => 'X', 'parameters' => ['a' => 1]]],
        'usage' => ['promptTokens' => 3, 'completionTokens' => 4],
    ]);

    expect($parsed['text'])->toBe('The answer')
        ->and($parsed['toolCalls'][0]->name)->toBe('X')
        ->and($parsed['toolCalls'][0]->arguments)->toEqual(['a' => 1])
        ->and($parsed['finishReason'])->toBe(FinishReason::Stop)
        ->and($parsed['usage']->promptTokens)->toBe(3);
});

test('finish reasons are mapped per family', function () {
    $gateway = oracleGateway();

    expect($gateway->callGenericFinishReason('stop'))->toBe(FinishReason::Stop)
        ->and($gateway->callGenericFinishReason('length'))->toBe(FinishReason::Length)
        ->and($gateway->callGenericFinishReason('tool_calls'))->toBe(FinishReason::ToolCalls)
        ->and($gateway->callGenericFinishReason('content_filter'))->toBe(FinishReason::ContentFilter)
        ->and($gateway->callCohereFinishReason('COMPLETE'))->toBe(FinishReason::Stop)
        ->and($gateway->callCohereFinishReason('MAX_TOKENS'))->toBe(FinishReason::Length)
        ->and($gateway->callCohereFinishReason('ERROR_TOXIC'))->toBe(FinishReason::ContentFilter)
        ->and($gateway->callCohereFinishReason('ERROR'))->toBe(FinishReason::Error);
});

test('schema is converted to cohere parameter definitions', function () {
    $definitions = oracleGateway()->callSchemaToParameterDefinitions([
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'A name'],
            'age' => ['type' => 'integer'],
        ],
        'required' => ['name'],
    ]);

    expect($definitions)->toEqual([
        'name' => ['description' => 'A name', 'type' => 'string', 'isRequired' => true],
        'age' => ['description' => '', 'type' => 'integer', 'isRequired' => false],
    ]);
});

test('resolve max steps returns one without tools and scales with tool count', function () {
    $gateway = oracleGateway();

    expect($gateway->callResolveMaxSteps([], null))->toBe(1)
        ->and($gateway->callResolveMaxSteps([new OracleSampleTool, new OracleSampleTool], null))->toBe(3)
        ->and($gateway->callResolveMaxSteps([new OracleSampleTool], new TextGenerationOptions(maxSteps: 9)))->toBe(9);
});

test('stream text delta is extracted from both family shapes', function () {
    $gateway = oracleGateway();

    expect($gateway->callStreamTextDelta(['text' => 'cohere chunk']))->toBe('cohere chunk')
        ->and($gateway->callStreamTextDelta(['message' => ['content' => [['type' => 'TEXT', 'text' => 'generic chunk']]]]))->toBe('generic chunk')
        ->and($gateway->callStreamTextDelta(['finishReason' => 'stop']))->toBe('');
});
