<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Testing\File as TestingFile;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\S3Document;
use Laravel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Agents\CachePointsAgent;
use Tests\Fixtures\Agents\ProviderOptionsAgent;
use Tests\Fixtures\Tools\NamedTool;

function textGateway(): object
{
    return new class extends BedrockTextGateway
    {
        public function callMapAttachments(Collection $attachments): array
        {
            return $this->mapAttachments($attachments);
        }

        public function callFormatMessages(array $messages): array
        {
            return $this->formatMessages($messages);
        }

        public function callFormatTools(array $tools): array
        {
            return $this->formatTools($tools);
        }

        public function callBuildSchemaTools(array $schema, array $tools): array
        {
            return $this->buildSchemaTools($schema, $tools);
        }

        public function callBuildToolConfig(?array $schemaTools, ?array $formattedTools, bool $toolsEmpty, bool $isFinalStep): ?array
        {
            return $this->buildToolConfig($schemaTools, $formattedTools, $toolsEmpty, $isFinalStep);
        }

        public function callBuildInferenceConfig(?TextGenerationOptions $options): array
        {
            return $this->buildInferenceConfig($options);
        }

        public function callBuildAssistantConversationMessage(string $text, array $toolCalls): array
        {
            return $this->buildAssistantConversationMessage($text, $toolCalls);
        }

        public function callBuildToolResultConversationMessage(array $toolResults): array
        {
            return $this->buildToolResultConversationMessage($toolResults);
        }

        public function callBuildConverseParameters(
            string $model,
            ?string $instructions,
            array $conversationMessages,
            ?array $schemaTools,
            ?array $formattedTools,
            bool $toolsEmpty,
            ?TextGenerationOptions $options,
            bool $isFinalStep,
        ): array {
            return $this->buildConverseParameters(
                $model,
                $instructions,
                $conversationMessages,
                $schemaTools,
                $formattedTools,
                $toolsEmpty,
                $options,
                $isFinalStep,
            );
        }

        public function callApplyCachePoints(array $parameters, array|string|null $locations): array
        {
            return $this->applyCachePoints($parameters, $locations);
        }

        public function callResolveMaxSteps(array $tools, ?TextGenerationOptions $options): int
        {
            return $this->resolveMaxSteps($tools, $options);
        }

        public function callGetDocumentFormat(Document $document): ?string
        {
            return $this->getDocumentFormat($document);
        }

        public function callFormatUserMessage(UserMessage $message): array
        {
            return $this->formatUserMessage($message);
        }

        public function callGetImageFormat(Image $image): string
        {
            return $this->getImageFormat($image);
        }
    };
}

class BedrockSampleTool implements Tool
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
        return [];
    }
}

test('user message is formatted with text block', function (): void {
    $formatted = textGateway()->callFormatMessages([
        new UserMessage('hello'),
    ]);

    expect($formatted)->toEqual([
        ['role' => 'user', 'content' => [['text' => 'hello']]],
    ]);
});

test('assistant message is formatted with text block', function (): void {
    $formatted = textGateway()->callFormatMessages([
        new AssistantMessage('hi there'),
    ]);

    expect($formatted)->toEqual([
        ['role' => 'assistant', 'content' => [['text' => 'hi there']]],
    ]);
});

test('assistant message with tool calls is formatted with tool use blocks', function (): void {
    $assistant = new AssistantMessage('calling tool', new Collection([
        new ToolCall('tool-1', 'RandomGenerator', ['n' => 5]),
    ]));

    $formatted = textGateway()->callFormatMessages([$assistant]);

    expect($formatted[0])->toEqual([
        'role' => 'assistant',
        'content' => [
            ['text' => 'calling tool'],
            ['toolUse' => [
                'toolUseId' => 'tool-1',
                'name' => 'RandomGenerator',
                'input' => ['n' => 5],
            ]],
        ],
    ]);
});

test('assistant message with empty tool input is formatted as a json object', function (): void {
    $assistant = new AssistantMessage('', new Collection([
        new ToolCall('tool-1', 'NoArgGenerator', []),
    ]));

    $formatted = textGateway()->callFormatMessages([$assistant]);
    $input = $formatted[0]['content'][0]['toolUse']['input'];

    expect($input)->toBeInstanceOf(stdClass::class)
        ->and(json_encode($input))->toBe('{}');
});

test('assistant message with providerContentBlocks is formatted verbatim', function (): void {
    $message = new AssistantMessage(
        'Hello',
        new Collection([new ToolCall('tool-1', 'doIt', [])]),
        providerContentBlocks: [
            ['reasoningContent' => ['reasoningText' => ['text' => 'think', 'signature' => 'sig']]],
            ['text' => 'Hello'],
            ['toolUse' => ['toolUseId' => 'tool-1', 'name' => 'doIt', 'input' => []]],
        ],
    );

    $formatted = textGateway()->callFormatMessages([$message]);
    $content = $formatted[0]['content'];

    expect($formatted[0]['role'])->toBe('assistant')
        ->and($content[0])->toBe(['reasoningContent' => ['reasoningText' => ['text' => 'think', 'signature' => 'sig']]])
        ->and($content[1])->toBe(['text' => 'Hello'])
        ->and($content[2]['toolUse']['toolUseId'])->toBe('tool-1')
        ->and($content[2]['toolUse']['name'])->toBe('doIt')
        ->and($content[2]['toolUse']['input'])->toBeInstanceOf(stdClass::class)
        ->and(json_encode($content[2]['toolUse']['input']))->toBe('{}');
});

test('tool result message is formatted with tool result blocks', function (): void {
    $message = new ToolResultMessage(new Collection([
        new ToolResult('tool-1', 'RandomGenerator', [], 'the result'),
    ]));

    $formatted = textGateway()->callFormatMessages([$message]);

    expect($formatted[0])->toEqual([
        'role' => 'user',
        'content' => [[
            'toolResult' => [
                'toolUseId' => 'tool-1',
                'content' => [['text' => 'the result']],
            ],
        ]],
    ]);
});

test('tool result non-string results are json encoded', function (): void {
    $message = new ToolResultMessage(new Collection([
        new ToolResult('tool-1', 'RandomGenerator', [], ['answer' => 42]),
    ]));

    $formatted = textGateway()->callFormatMessages([$message]);

    expect($formatted[0]['content'][0]['toolResult']['content'][0]['text'])
        ->toBe('{"answer":42}');
});

test('generic non assistant message falls back to user role', function (): void {
    $formatted = textGateway()->callFormatMessages([
        new Message('tool_result', 'tool output'),
    ]);

    expect($formatted[0])->toEqual([
        'role' => 'user',
        'content' => [['text' => 'tool output']],
    ]);
});

test('array shaped message is formatted', function (): void {
    $formatted = textGateway()->callFormatMessages([
        ['role' => 'assistant', 'content' => 'hello'],
    ]);

    expect($formatted[0])->toEqual([
        'role' => 'assistant',
        'content' => [['text' => 'hello']],
    ]);
});

test('user message with pdf document attachment produces document block', function (): void {
    $user = new UserMessage('here', [new Base64Document(base64_encode('pdf-bytes'), 'application/pdf')]);

    $formatted = textGateway()->callFormatUserMessage($user);

    expect($formatted['role'])->toBe('user')
        ->and($formatted['content'][0])->toEqual(['text' => 'here'])
        ->and($formatted['content'][1]['document']['format'])->toBe('pdf')
        ->and($formatted['content'][1]['document']['name'])->toBe('document')
        ->and($formatted['content'][1]['document']['source']['bytes'])->toBe('pdf-bytes');
});

test('s3 document attachment is sent as s3Location reference', function (): void {
    $document = (new S3Document('s3://my-bucket/path/report.pdf', null, 'application/pdf'))->as('report');
    $user = new UserMessage('summarize this', [$document]);

    $formatted = textGateway()->callFormatUserMessage($user);

    expect($formatted['content'][1]['document']['format'])->toBe('pdf')
        ->and($formatted['content'][1]['document']['name'])->toBe('report')
        ->and($formatted['content'][1]['document']['source'])->toEqual([
            's3Location' => ['uri' => 's3://my-bucket/path/report.pdf'],
        ]);
});

test('s3 document attachment includes bucketOwner when set', function (): void {
    $document = (new S3Document('s3://my-bucket/path/report.pdf', '123456789012', 'application/pdf'))->as('report');
    $user = new UserMessage('summarize this', [$document]);

    $formatted = textGateway()->callFormatUserMessage($user);

    expect($formatted['content'][1]['document']['source'])->toEqual([
        's3Location' => [
            'uri' => 's3://my-bucket/path/report.pdf',
            'bucketOwner' => '123456789012',
        ],
    ]);
});

test('s3 document can be created with constructor from s3 url', function (): void {
    $document = new S3Document('s3://my-bucket/path/report.pdf');

    expect($document)->toBeInstanceOf(S3Document::class)
        ->and($document->url)->toBe('s3://my-bucket/path/report.pdf');
});

test('s3 document content throws unsupported exception', function (): void {
    $document = new S3Document('s3://my-bucket/path/report.pdf');

    $document->content();
})->throws(InvalidArgumentException::class);

test('document format maps common mime types', function (): void {
    $gateway = textGateway();

    expect($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'application/pdf')))->toBe('pdf');
    expect($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'text/csv')))->toBe('csv');
    expect($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'application/msword')))->toBe('doc');
    expect($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')))->toBe('docx');
    expect($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'application/vnd.ms-excel')))->toBe('xls');
    expect($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')))->toBe('xlsx');
    expect($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'text/html')))->toBe('html');
    expect($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'text/markdown')))->toBe('md');
    expect($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'text/x-markdown')))->toBe('md');
    expect($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'), 'text/plain; charset=utf-8')))->toBe('txt');
    expect($gateway->callGetDocumentFormat(new Base64Document(base64_encode('doc-bytes'))))->toBeNull();
});

test('user message with base64 image attachment produces image block', function (): void {
    $user = new UserMessage('see this', [new Base64Image(base64_encode('image-bytes'), 'image/png')]);

    $formatted = textGateway()->callFormatUserMessage($user);

    expect($formatted['role'])->toBe('user')
        ->and($formatted['content'][0])->toEqual(['text' => 'see this'])
        ->and($formatted['content'][1])->toEqual([
            'image' => [
                'format' => 'png',
                'source' => ['bytes' => 'image-bytes'],
            ],
        ]);
});

test('local image attachment is read from disk into bytes', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'bedrock-image-');
    file_put_contents($tmp, 'local-image-bytes');

    try {
        $mapped = textGateway()->callMapAttachments(new Collection([
            new LocalImage($tmp, 'image/jpeg'),
        ]));

        expect($mapped[0])->toEqual([
            'image' => [
                'format' => 'jpeg',
                'source' => ['bytes' => 'local-image-bytes'],
            ],
        ]);
    } finally {
        @unlink($tmp);
    }
});

test('remote image attachment is rejected', function (): void {
    expect(fn () => textGateway()->callMapAttachments(new Collection([
        new RemoteImage('https://example.com/cat.png', 'image/png'),
    ])))->toThrow(InvalidArgumentException::class, 'Remote attachments are not supported by Bedrock');
});

test('provider image attachment is rejected', function (): void {
    expect(fn () => textGateway()->callMapAttachments(new Collection([
        new ProviderImage('img_123'),
    ])))->toThrow(InvalidArgumentException::class, 'Provider-stored attachments are not supported by Bedrock');
});

test('uploaded file attachment is rejected as unsupported', function (): void {
    expect(fn () => textGateway()->callMapAttachments(new Collection([
        TestingFile::create('cat.png'),
    ])))->toThrow(InvalidArgumentException::class, 'Unsupported attachment type');
});

test('image format maps common image mime types', function (): void {
    $gateway = textGateway();

    expect($gateway->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/png')))->toBe('png');
    expect($gateway->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/jpeg')))->toBe('jpeg');
    expect($gateway->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/jpg')))->toBe('jpeg');
    expect($gateway->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/gif')))->toBe('gif');
    expect($gateway->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/webp')))->toBe('webp');
});

test('image format throws when mime type is unsupported', function (): void {
    expect(fn () => textGateway()->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), 'image/unsupported')))
        ->toThrow(InvalidArgumentException::class, 'Unsupported image MIME type [image/unsupported]');
});

test('image format throws when mime type is missing', function (): void {
    expect(fn () => textGateway()->callGetImageFormat(new Base64Image(base64_encode('image-bytes'), null)))
        ->toThrow(InvalidArgumentException::class, 'Unable to determine MIME type');
});

test('format tools produces converse tool specs', function (): void {
    $tools = [new BedrockSampleTool];

    $formatted = textGateway()->callFormatTools($tools);

    expect($formatted)->toHaveCount(1)
        ->and($formatted[0]['toolSpec']['name'])->toBe('BedrockSampleTool')
        ->and($formatted[0]['toolSpec']['description'])->toBe('Sample description')
        ->and($formatted[0]['toolSpec']['inputSchema']['json'])->toBeArray();
});

test('format tools uses a tool name() method when present', function (): void {
    $formatted = textGateway()->callFormatTools([new NamedTool('aliased_tool')]);

    expect($formatted)->toHaveCount(1)
        ->and($formatted[0]['toolSpec']['name'])->toBe('aliased_tool');
});

test('format tools ignores non tool values', function (): void {
    $formatted = textGateway()->callFormatTools([new BedrockSampleTool, 'not-a-tool']);

    expect($formatted)->toHaveCount(1);
});

test('build schema tools prepends structured output tool', function (): void {
    $schemaTools = textGateway()->callBuildSchemaTools([], [new BedrockSampleTool]);

    expect($schemaTools)->toHaveCount(2)
        ->and($schemaTools[0]['toolSpec']['name'])->toBe('structured_output')
        ->and($schemaTools[0]['toolSpec']['inputSchema']['json'])->toBeArray()
        ->and($schemaTools[1]['toolSpec']['name'])->toBe('BedrockSampleTool');
});

test('build tool config returns null when no tools present', function (): void {
    expect(textGateway()->callBuildToolConfig(null, null, true, false))->toBeNull();
});

test('build tool config returns formatted tools when no schema present', function (): void {
    $formatted = [['toolSpec' => ['name' => 'X']]];

    expect(textGateway()->callBuildToolConfig(null, $formatted, false, false))
        ->toEqual(['tools' => $formatted]);
});

test('build tool config uses auto tool choice on non final schema step', function (): void {
    $schemaTools = [['toolSpec' => ['name' => 'structured_output']]];

    $config = textGateway()->callBuildToolConfig($schemaTools, null, false, false);

    expect($config['tools'])->toBe($schemaTools)
        ->and($config['toolChoice'])->toHaveKey('auto');
});

test('build tool config forces structured tool on final schema step', function (): void {
    $schemaTools = [['toolSpec' => ['name' => 'structured_output']]];

    $config = textGateway()->callBuildToolConfig($schemaTools, null, false, true);

    expect($config['toolChoice'])->toEqual(['tool' => ['name' => 'structured_output']]);
});

test('build tool config forces structured tool when no real tools provided', function (): void {
    $schemaTools = [['toolSpec' => ['name' => 'structured_output']]];

    $config = textGateway()->callBuildToolConfig($schemaTools, null, true, false);

    expect($config['toolChoice'])->toEqual(['tool' => ['name' => 'structured_output']]);
});

test('build inference config is empty without options', function (): void {
    expect(textGateway()->callBuildInferenceConfig(null))->toEqual([]);
});

test('build inference config maps max tokens and temperature', function (): void {
    $options = new TextGenerationOptions(maxTokens: 500, temperature: 0.7);

    expect(textGateway()->callBuildInferenceConfig($options))->toEqual([
        'maxTokens' => 500,
        'temperature' => 0.7,
    ]);
});

test('build inference config includes temperature of zero', function (): void {
    $options = new TextGenerationOptions(temperature: 0.0);

    expect(textGateway()->callBuildInferenceConfig($options))->toEqual([
        'temperature' => 0.0,
    ]);
});

test('build assistant conversation message omits text block when empty', function (): void {
    $message = textGateway()->callBuildAssistantConversationMessage('', [
        new ToolCall('t-1', 'X', []),
    ]);

    expect($message['content'])->toHaveCount(1)
        ->and($message['content'][0]['toolUse']['name'])->toBe('X');
});

test('build assistant conversation message includes text and tool calls', function (): void {
    $message = textGateway()->callBuildAssistantConversationMessage('thinking', [
        new ToolCall('t-1', 'X', ['a' => 1]),
    ]);

    expect($message['content'])->toEqual([
        ['text' => 'thinking'],
        ['toolUse' => ['toolUseId' => 't-1', 'name' => 'X', 'input' => ['a' => 1]]],
    ]);
});

test('build tool result conversation message uses array form', function (): void {
    $message = textGateway()->callBuildToolResultConversationMessage([
        new ToolResult('t-1', 'X', [], ['out' => true]),
    ]);

    expect($message)->toEqual([
        'role' => 'user',
        'content' => [[
            'toolResult' => [
                'toolUseId' => 't-1',
                'content' => [['text' => '{"out":true}']],
            ],
        ]],
    ]);
});

test('resolve max steps returns one when no tools', function (): void {
    expect(textGateway()->callResolveMaxSteps([], null))->toBe(1);
});

test('resolve max steps honors explicit option', function (): void {
    $options = new TextGenerationOptions(maxSteps: 10);

    expect(textGateway()->callResolveMaxSteps([new BedrockSampleTool], $options))->toBe(10);
});

test('resolve max steps falls back to tool count times one and a half', function (): void {
    $tools = [new BedrockSampleTool, new BedrockSampleTool];

    expect(textGateway()->callResolveMaxSteps($tools, null))->toBe(3);
});

test('build converse parameters attaches system instructions', function (): void {
    $params = textGateway()->callBuildConverseParameters(
        'claude-sonnet',
        'you are helpful',
        [['role' => 'user', 'content' => [['text' => 'hi']]]],
        null,
        null,
        true,
        null,
        false,
    );

    expect($params['modelId'])->toBe('claude-sonnet')
        ->and($params['system'])->toEqual([['text' => 'you are helpful']])
        ->and($params['messages'])->toEqual([['role' => 'user', 'content' => [['text' => 'hi']]]])
        ->and($params)->not->toHaveKey('toolConfig')
        ->and($params)->not->toHaveKey('inferenceConfig');
});

test('build converse parameters includes tool config and inference config when present', function (): void {
    $formattedTools = [['toolSpec' => ['name' => 'X']]];
    $options = new TextGenerationOptions(maxTokens: 100);

    $params = textGateway()->callBuildConverseParameters(
        'claude-sonnet',
        null,
        [],
        null,
        $formattedTools,
        false,
        $options,
        false,
    );

    expect($params)->not->toHaveKey('system')
        ->and($params['toolConfig'])->toEqual(['tools' => $formattedTools])
        ->and($params['inferenceConfig'])->toEqual(['maxTokens' => 100]);
});

test('build converse parameters flat-merges agent provider options for bedrock', function (): void {
    $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

    $params = textGateway()->callBuildConverseParameters(
        'claude-sonnet',
        null,
        [['role' => 'user', 'content' => [['text' => 'hi']]]],
        null,
        null,
        true,
        $options,
        false,
    );

    expect($params['additionalModelRequestFields'])->toEqual([
        'thinking' => [
            'type' => 'adaptive',
        ],
        'output_config' => [
            'effort' => 'high',
        ],
    ])->and($params['guardrailConfig'])->toEqual([
        'guardrailIdentifier' => 'gr-1',
        'guardrailVersion' => '1',
    ]);
});

test('build converse parameters omits provider options when agent has none', function (): void {
    $params = textGateway()->callBuildConverseParameters(
        'claude-sonnet',
        null,
        [['role' => 'user', 'content' => [['text' => 'hi']]]],
        null,
        null,
        true,
        null,
        false,
    );

    expect($params)->not->toHaveKey('additionalModelRequestFields')
        ->and($params)->not->toHaveKey('guardrailConfig');
});

test('apply cache points appends markers to the requested locations', function () {
    $parameters = [
        'system' => [['text' => 'you are helpful']],
        'toolConfig' => ['tools' => [['toolSpec' => ['name' => 'X']]]],
    ];

    $result = textGateway()->callApplyCachePoints($parameters, ['system', 'tools']);

    expect($result['system'])->toEqual([
        ['text' => 'you are helpful'],
        ['cachePoint' => ['type' => 'default']],
    ])->and($result['toolConfig']['tools'])->toEqual([
        ['toolSpec' => ['name' => 'X']],
        ['cachePoint' => ['type' => 'default']],
    ]);
});

test('apply cache points only touches the locations asked for', function () {
    $parameters = [
        'system' => [['text' => 'you are helpful']],
        'toolConfig' => ['tools' => [['toolSpec' => ['name' => 'X']]]],
    ];

    $result = textGateway()->callApplyCachePoints($parameters, 'system');

    expect($result['system'])->toEqual([
        ['text' => 'you are helpful'],
        ['cachePoint' => ['type' => 'default']],
    ])->and($result['toolConfig']['tools'])->toEqual([['toolSpec' => ['name' => 'X']]]);
});

test('apply cache points is a no-op when nothing is requested or the target is absent', function () {
    $parameters = ['system' => [['text' => 'hi']]];

    expect(textGateway()->callApplyCachePoints($parameters, null))->toEqual($parameters)
        ->and(textGateway()->callApplyCachePoints($parameters, []))->toEqual($parameters)
        ->and(textGateway()->callApplyCachePoints(['messages' => []], ['system', 'tools']))
        ->toEqual(['messages' => []]);
});

test('build converse parameters applies cache points without flat-merging the directive', function () {
    $options = TextGenerationOptions::forAgent(new CachePointsAgent);

    $params = textGateway()->callBuildConverseParameters(
        'claude-sonnet',
        'you are helpful',
        [['role' => 'user', 'content' => [['text' => 'hi']]]],
        null,
        [['toolSpec' => ['name' => 'X']]],
        false,
        $options,
        false,
    );

    expect($params['system'])->toEqual([
        ['text' => 'you are helpful'],
        ['cachePoint' => ['type' => 'default']],
    ])->and($params['toolConfig']['tools'])->toEqual([
        ['toolSpec' => ['name' => 'X']],
        ['cachePoint' => ['type' => 'default']],
    ])->and($params)->not->toHaveKey('cachePoints');
});
