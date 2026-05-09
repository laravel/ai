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
use Laravel\Ai\Gateway\Bedrock\BedrockTextGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\Request;
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

        public function callGetDocumentFormat(Document $document): string
        {
            return $this->getDocumentFormat($document);
        }

        public function callFormatUserMessage(UserMessage $message): array
        {
            return $this->formatUserMessage($message);
        }

        public function callGetImageFormat(Image $image)
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

test('user message is formatted with text block', function () {
    $formatted = textGateway()->callFormatMessages([
        new UserMessage('hello'),
    ]);

    expect($formatted)->toEqual([
        ['role' => 'user', 'content' => [['text' => 'hello']]],
    ]);
});

test('assistant message is formatted with text block', function () {
    $formatted = textGateway()->callFormatMessages([
        new AssistantMessage('hi there'),
    ]);

    expect($formatted)->toEqual([
        ['role' => 'assistant', 'content' => [['text' => 'hi there']]],
    ]);
});

test('assistant message with tool calls is formatted with tool use blocks', function () {
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

test('assistant message with empty tool input is formatted as a json object', function () {
    $assistant = new AssistantMessage('', new Collection([
        new ToolCall('tool-1', 'NoArgGenerator', []),
    ]));

    $formatted = textGateway()->callFormatMessages([$assistant]);
    $input = $formatted[0]['content'][0]['toolUse']['input'];

    expect($input)->toBeInstanceOf(stdClass::class)
        ->and(json_encode($input))->toBe('{}');
});

test('tool result message is formatted with tool result blocks', function () {
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

test('tool result non-string results are json encoded', function () {
    $message = new ToolResultMessage(new Collection([
        new ToolResult('tool-1', 'RandomGenerator', [], ['answer' => 42]),
    ]));

    $formatted = textGateway()->callFormatMessages([$message]);

    expect($formatted[0]['content'][0]['toolResult']['content'][0]['text'])
        ->toBe('{"answer":42}');
});

test('generic non assistant message falls back to user role', function () {
    $formatted = textGateway()->callFormatMessages([
        new Message('tool_result', 'tool output'),
    ]);

    expect($formatted[0])->toEqual([
        'role' => 'user',
        'content' => [['text' => 'tool output']],
    ]);
});

test('array shaped message is formatted', function () {
    $formatted = textGateway()->callFormatMessages([
        ['role' => 'assistant', 'content' => 'hello'],
    ]);

    expect($formatted[0])->toEqual([
        'role' => 'assistant',
        'content' => [['text' => 'hello']],
    ]);
});

test('user message with pdf document attachment produces document block', function () {
    $user = new UserMessage('here', [new Base64Document(base64_encode('pdf-bytes'), 'application/pdf')]);

    $formatted = textGateway()->callFormatUserMessage($user);

    expect($formatted['role'])->toBe('user')
        ->and($formatted['content'][0])->toEqual(['text' => 'here'])
        ->and($formatted['content'][1]['document']['format'])->toBe('pdf')
        ->and($formatted['content'][1]['document']['name'])->toBe('document')
        ->and($formatted['content'][1]['document']['source']['bytes'])->toBe('pdf-bytes');
});

test('document format maps common mime types', function () {
    $gateway = textGateway();

    expect($gateway->callGetDocumentFormat(new Base64Document('', 'application/pdf')))->toBe('pdf');
    expect($gateway->callGetDocumentFormat(new Base64Document('', 'text/csv')))->toBe('csv');
    expect($gateway->callGetDocumentFormat(new Base64Document('', 'application/msword')))->toBe('doc');
    expect($gateway->callGetDocumentFormat(new Base64Document('', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')))->toBe('docx');
    expect($gateway->callGetDocumentFormat(new Base64Document('', 'application/vnd.ms-excel')))->toBe('xls');
    expect($gateway->callGetDocumentFormat(new Base64Document('', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')))->toBe('xlsx');
    expect($gateway->callGetDocumentFormat(new Base64Document('', 'text/html')))->toBe('html');
    expect($gateway->callGetDocumentFormat(new Base64Document('', 'text/markdown')))->toBe('md');
    expect($gateway->callGetDocumentFormat(new Base64Document('', 'text/x-markdown')))->toBe('md');
    expect($gateway->callGetDocumentFormat(new Base64Document('', 'text/plain; charset=utf-8')))->toBe('txt');
    expect($gateway->callGetDocumentFormat(new Base64Document('', null)))->toBe('txt');
});

test('user message with base64 image attachment produces image block', function () {
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

test('local image attachment is read from disk into bytes', function () {
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

test('remote image attachment is rejected', function () {
    expect(fn () => textGateway()->callMapAttachments(new Collection([
        new RemoteImage('https://example.com/cat.png', 'image/png'),
    ])))->toThrow(InvalidArgumentException::class, 'Remote attachments are not supported by Bedrock');
});

test('provider image attachment is rejected', function () {
    expect(fn () => textGateway()->callMapAttachments(new Collection([
        new ProviderImage('img_123'),
    ])))->toThrow(InvalidArgumentException::class, 'Provider-stored attachments are not supported by Bedrock');
});

test('uploaded file attachment is rejected as unsupported', function () {
    expect(fn () => textGateway()->callMapAttachments(new Collection([
        TestingFile::create('cat.png'),
    ])))->toThrow(InvalidArgumentException::class, 'Unsupported attachment type');
});

test('image format maps common image mime types', function () {
    $gateway = textGateway();

    expect($gateway->callGetImageFormat(new Base64Image('', 'image/png')))->toBe('png');
    expect($gateway->callGetImageFormat(new Base64Image('', 'image/jpeg')))->toBe('jpeg');
    expect($gateway->callGetImageFormat(new Base64Image('', 'image/jpg')))->toBe('jpeg');
    expect($gateway->callGetImageFormat(new Base64Image('', 'image/gif')))->toBe('gif');
    expect($gateway->callGetImageFormat(new Base64Image('', 'image/webp')))->toBe('webp');
});

test('image format throws when mime type is unsupported', function () {
    expect(fn () => textGateway()->callGetImageFormat(new Base64Image('', 'image/unsupported')))
        ->toThrow(InvalidArgumentException::class, 'Unsupported image MIME type [image/unsupported]');
});

test('image format throws when mime type is missing', function () {
    expect(fn () => textGateway()->callGetImageFormat(new Base64Image('', null)))
        ->toThrow(InvalidArgumentException::class, 'Unable to determine MIME type');
});

test('format tools produces converse tool specs', function () {
    $tools = [new BedrockSampleTool];

    $formatted = textGateway()->callFormatTools($tools);

    expect($formatted)->toHaveCount(1)
        ->and($formatted[0]['toolSpec']['name'])->toBe('BedrockSampleTool')
        ->and($formatted[0]['toolSpec']['description'])->toBe('Sample description')
        ->and($formatted[0]['toolSpec']['inputSchema']['json'])->toBeArray();
});

test('format tools uses a tool name() method when present', function () {
    $formatted = textGateway()->callFormatTools([new NamedTool('aliased_tool')]);

    expect($formatted)->toHaveCount(1)
        ->and($formatted[0]['toolSpec']['name'])->toBe('aliased_tool');
});

test('format tools ignores non tool values', function () {
    $formatted = textGateway()->callFormatTools([new BedrockSampleTool, 'not-a-tool']);

    expect($formatted)->toHaveCount(1);
});

test('build schema tools prepends structured output tool', function () {
    $schemaTools = textGateway()->callBuildSchemaTools([], [new BedrockSampleTool]);

    expect($schemaTools)->toHaveCount(2)
        ->and($schemaTools[0]['toolSpec']['name'])->toBe('structured_output')
        ->and($schemaTools[0]['toolSpec']['inputSchema']['json'])->toBeArray()
        ->and($schemaTools[1]['toolSpec']['name'])->toBe('BedrockSampleTool');
});

test('build tool config returns null when no tools present', function () {
    expect(textGateway()->callBuildToolConfig(null, null, true, false))->toBeNull();
});

test('build tool config returns formatted tools when no schema present', function () {
    $formatted = [['toolSpec' => ['name' => 'X']]];

    expect(textGateway()->callBuildToolConfig(null, $formatted, false, false))
        ->toEqual(['tools' => $formatted]);
});

test('build tool config uses auto tool choice on non final schema step', function () {
    $schemaTools = [['toolSpec' => ['name' => 'structured_output']]];

    $config = textGateway()->callBuildToolConfig($schemaTools, null, false, false);

    expect($config['tools'])->toBe($schemaTools)
        ->and($config['toolChoice'])->toHaveKey('auto');
});

test('build tool config forces structured tool on final schema step', function () {
    $schemaTools = [['toolSpec' => ['name' => 'structured_output']]];

    $config = textGateway()->callBuildToolConfig($schemaTools, null, false, true);

    expect($config['toolChoice'])->toEqual(['tool' => ['name' => 'structured_output']]);
});

test('build tool config forces structured tool when no real tools provided', function () {
    $schemaTools = [['toolSpec' => ['name' => 'structured_output']]];

    $config = textGateway()->callBuildToolConfig($schemaTools, null, true, false);

    expect($config['toolChoice'])->toEqual(['tool' => ['name' => 'structured_output']]);
});

test('build inference config is empty without options', function () {
    expect(textGateway()->callBuildInferenceConfig(null))->toEqual([]);
});

test('build inference config maps max tokens and temperature', function () {
    $options = new TextGenerationOptions(maxTokens: 500, temperature: 0.7);

    expect(textGateway()->callBuildInferenceConfig($options))->toEqual([
        'maxTokens' => 500,
        'temperature' => 0.7,
    ]);
});

test('build inference config includes temperature of zero', function () {
    $options = new TextGenerationOptions(temperature: 0.0);

    expect(textGateway()->callBuildInferenceConfig($options))->toEqual([
        'temperature' => 0.0,
    ]);
});

test('assistant message omits text block when content is empty', function () {
    $assistant = new AssistantMessage('', new Collection([
        new ToolCall('t-1', 'X', []),
    ]));

    $formatted = textGateway()->callFormatMessages([$assistant]);

    expect($formatted[0]['content'])->toHaveCount(1)
        ->and($formatted[0]['content'][0]['toolUse']['name'])->toBe('X');
});

test('build converse parameters attaches system instructions', function () {
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

test('build converse parameters includes tool config and inference config when present', function () {
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
