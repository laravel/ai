<?php

namespace Laravel\Ai\Gateway\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Generator;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Gateway\Concerns\HandlesRateLimiting;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request as ToolRequest;
use Throwable;

class BedrockTextGateway implements EmbeddingGateway, TextGateway
{
    use HandlesRateLimiting;

    protected $invokingToolCallback;

    protected $toolInvokedCallback;

    public function __construct()
    {
        $this->invokingToolCallback = fn () => true;
        $this->toolInvokedCallback = fn () => true;
    }

    /**
     * Specify callbacks that should be invoked when tools are invoking / invoked.
     */
    public function onToolInvocation(\Closure $invoking, \Closure $invoked): self
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;

        return $this;
    }

    /**
     * Generate text using AWS Bedrock's Converse API.
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        $client = $this->createBedrockClient($provider, $timeout);
        $conversationMessages = $this->formatMessages($messages);
        $maxSteps = ! empty($tools) ? ($options?->maxSteps ?? round(count($tools) * 1.5)) : 1;
        $step = 0;

        $allToolCalls = [];
        $allToolResults = [];
        $finalOutput = '';
        $totalInputTokens = 0;
        $totalOutputTokens = 0;

        // When a schema is provided, inject a synthetic tool that forces the model
        // to return structured output via Bedrock's tool-use mechanism.
        $structuredOutputToolName = null;
        if ($schema) {
            $structuredOutputToolName = 'structured_output';
            $schemaTools = [
                [
                    'toolSpec' => [
                        'name'        => $structuredOutputToolName,
                        'description' => 'Return the response as a structured JSON object matching the provided schema.',
                        'inputSchema' => [
                            'json' => (new ObjectSchema($schema))->toArray(),
                        ],
                    ],
                ],
            ];
            // Real agent tools (if any) come after the structured output tool
            $schemaTools = array_merge($schemaTools, $this->formatTools($tools));
        }

        while ($step < $maxSteps) {
            $parameters = [
                'modelId' => $model,
                'messages' => $conversationMessages,
            ];

            if ($instructions) {
                $parameters['system'] = [
                    ['text' => $instructions],
                ];
            }

            if ($structuredOutputToolName) {
                // Force structured output via tool use
                $parameters['toolConfig'] = [
                    'tools'      => $schemaTools,
                    'toolChoice' => ['tool' => ['name' => $structuredOutputToolName]],
                ];
            } elseif (! empty($tools)) {
                $parameters['toolConfig'] = [
                    'tools' => $this->formatTools($tools),
                ];
            }

            if ($options) {
                $inferenceConfig = [];

                if ($options->maxTokens) {
                    $inferenceConfig['maxTokens'] = $options->maxTokens;
                }

                if ($options->temperature !== null) {
                    $inferenceConfig['temperature'] = $options->temperature;
                }

                if (! empty($inferenceConfig)) {
                    $parameters['inferenceConfig'] = $inferenceConfig;
                }
            }

            try {
                $response = $this->withRateLimitHandling(
                    $provider->name(),
                    fn () => $client->converse($parameters)
                );

                $result = $response->toArray();
            } catch (Throwable $e) {
                throw BedrockException::toAiException($e, $provider->name(), $model);
            }
            $content = $result['output']['message']['content'] ?? [];

            $totalInputTokens += $result['usage']['inputTokens'] ?? 0;
            $totalOutputTokens += $result['usage']['outputTokens'] ?? 0;

            // Extract text and tool calls
            $output = '';
            $toolCalls = [];

            foreach ($content as $block) {
                if (isset($block['text'])) {
                    $output .= $block['text'];
                } elseif (isset($block['toolUse'])) {
                    // Intercept the structured_output tool call — capture its input as JSON
                    if ($structuredOutputToolName && $block['toolUse']['name'] === $structuredOutputToolName) {
                        $finalOutput = json_encode($block['toolUse']['input'] ?? []);
                        continue;
                    }

                    $toolCalls[] = new ToolCall(
                        $block['toolUse']['toolUseId'],
                        $block['toolUse']['name'],
                        $block['toolUse']['input'] ?? []
                    );
                }
            }

            if (! $structuredOutputToolName) {
                $finalOutput = $output;
            }
            $step++;

            // If no tool calls, we're done
            if (empty($toolCalls)) {
                break;
            }

            $allToolCalls = array_merge($allToolCalls, $toolCalls);

            // Add assistant message with tool calls to conversation
            $conversationMessages[] = [
                'role' => 'assistant',
                'content' => array_merge(
                    ! empty($output) ? [['text' => $output]] : [],
                    array_map(fn ($toolCall) => [
                        'toolUse' => [
                            'toolUseId' => $toolCall->id,
                            'name' => $toolCall->name,
                            'input' => $toolCall->arguments,
                        ],
                    ], $toolCalls)
                ),
            ];

            // Execute tools and add results to conversation
            $toolResults = $this->executeTools($tools, $toolCalls);
            $allToolResults = array_merge($allToolResults, $toolResults);

            if (! empty($toolResults)) {
                $conversationMessages[] = [
                    'role' => 'user',
                    'content' => array_map(fn ($toolResult) => [
                        'toolResult' => [
                            'toolUseId' => $toolResult->id,
                            'content' => [
                                ['text' => is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result)],
                            ],
                        ],
                    ], $toolResults),
                ];
            }
        }

        $usage = new Usage(
            $totalInputTokens,
            $totalOutputTokens,
            $totalInputTokens + $totalOutputTokens
        );

        $meta = new Meta(
            $provider->name(),
            $model
        );

        if ($schema) {
            // Parse structured output from text response
            $structured = json_decode($finalOutput, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $structured = [];
            }

            return (new StructuredTextResponse(
                $structured,
                $finalOutput,
                $usage,
                $meta
            ))->withToolCallsAndResults(new Collection($allToolCalls), new Collection($allToolResults));
        }

        return (new TextResponse(
            $finalOutput,
            $usage,
            $meta
        ))->withToolCallsAndResults(new Collection($allToolCalls), new Collection($allToolResults));
    }

    /**
     * Stream text generation using AWS Bedrock's ConverseStream API.
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $client = $this->createBedrockClient($provider, $timeout);

        $parameters = [
            'modelId' => $model,
            'messages' => $this->formatMessages($messages),
        ];

        if ($instructions) {
            $parameters['system'] = [
                ['text' => $instructions],
            ];
        }

        if (! empty($tools)) {
            $parameters['toolConfig'] = [
                'tools' => $this->formatTools($tools),
            ];
        }

        if ($options) {
            $inferenceConfig = [];

            if ($options->maxTokens) {
                $inferenceConfig['maxTokens'] = $options->maxTokens;
            }

            if ($options->temperature !== null) {
                $inferenceConfig['temperature'] = $options->temperature;
            }

            if (! empty($inferenceConfig)) {
                $parameters['inferenceConfig'] = $inferenceConfig;
            }
        }

        try {
            $response = $this->withRateLimitHandling(
                $provider->name(),
                fn () => $client->converseStream($parameters)
            );
        } catch (Throwable $e) {
            throw BedrockException::toAiException($e, $provider->name(), $model);
        }

        $messageId = (string) Str::uuid();
        $inputTokens = 0;
        $outputTokens = 0;
        $timestamp = time();

        foreach ($response['stream'] as $event) {
            if (isset($event['contentBlockDelta']['delta']['text'])) {
                $delta = $event['contentBlockDelta']['delta']['text'];

                yield (new TextDelta(
                    (string) Str::uuid(),
                    $messageId,
                    $delta,
                    $timestamp
                ))->withInvocationId($invocationId);
            }

            if (isset($event['metadata']['usage'])) {
                $inputTokens = $event['metadata']['usage']['inputTokens'] ?? 0;
                $outputTokens = $event['metadata']['usage']['outputTokens'] ?? 0;
            }
        }

        yield (new StreamEnd(
            $messageId,
            'stop',
            new Usage($inputTokens, $outputTokens),
            $timestamp
        ))->withInvocationId($invocationId);
    }

    /**
     * Generate embeddings using AWS Bedrock.
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30
    ): EmbeddingsResponse {
        $client = $this->createBedrockClient($provider);

        $embeddings = [];
        $totalTokens = 0;

        foreach ($inputs as $input) {
            try {
                $response = $this->withRateLimitHandling(
                    $provider->name(),
                    fn () => $client->invokeModel([
                        'modelId' => $model,
                        'contentType' => 'application/json',
                        'accept' => 'application/json',
                        'body' => json_encode([
                            'inputText' => $input,
                            'dimensions' => $dimensions,
                        ]),
                    ])
                );

                $result = json_decode($response->get('body')->getContents(), true);
            } catch (Throwable $e) {
                throw BedrockException::toAiException($e, $provider->name(), $model);
            }

            // Handle different response formats for different models
            if (isset($result['embedding'])) {
                // Titan format
                $embeddings[] = $result['embedding'];
            } elseif (isset($result['embeddings']) && is_array($result['embeddings'])) {
                // Cohere format
                $embeddings[] = $result['embeddings'][0];
            }

            $totalTokens += $result['inputTextTokenCount'] ?? 0;
        }

        return new EmbeddingsResponse(
            $embeddings,
            $totalTokens,
            new Meta($provider->name(), $model)
        );
    }

    /**
     * Create a Bedrock Runtime client.
     */
    protected function createBedrockClient(TextProvider|EmbeddingProvider $provider, ?int $timeout = null): BedrockRuntimeClient
    {
        $credentials = $provider->providerCredentials();
        $config = $provider->additionalConfiguration();

        $clientConfig = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => '2023-09-30',
        ];

        if ($timeout) {
            $clientConfig['http'] = ['timeout' => $timeout];
        }

        // Handle different authentication methods
        if (! empty($credentials['access_key_id']) && ! empty($credentials['secret_access_key'])) {
            // IAM credentials (explicit)
            $clientConfig['credentials'] = [
                'key' => $credentials['access_key_id'],
                'secret' => $credentials['secret_access_key'],
            ];

            if (! empty($credentials['session_token'])) {
                $clientConfig['credentials']['token'] = $credentials['session_token'];
            }
        } elseif ($config['use_default_credential_provider'] ?? true) {
            // Use AWS default credential chain
            // No explicit credentials needed - AWS SDK will auto-discover from:
            // - Environment variables (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_SESSION_TOKEN)
            // - ~/.aws/credentials file
            // - IAM roles for EC2/ECS/Lambda
        }

        return new BedrockRuntimeClient($clientConfig);
    }

    /**
     * Format Laravel AI messages for Bedrock Converse API.
     */
    protected function formatMessages(array $messages): array
    {
        return (new Collection($messages))->map(function ($message) {
            if ($message instanceof AssistantMessage) {
                $content = [];

                // Add text content if present
                if (! empty($message->content)) {
                    $content[] = ['text' => $message->content];
                }

                // Add tool use blocks
                foreach ($message->toolCalls as $toolCall) {
                    $content[] = [
                        'toolUse' => [
                            'toolUseId' => $toolCall->id,
                            'name' => $toolCall->name,
                            'input' => $toolCall->arguments,
                        ],
                    ];
                }

                return [
                    'role' => 'assistant',
                    'content' => $content,
                ];
            }

            if ($message instanceof ToolResultMessage) {
                $content = [];

                foreach ($message->toolResults as $toolResult) {
                    $content[] = [
                        'toolResult' => [
                            'toolUseId' => $toolResult->id,
                            'content' => [
                                ['text' => is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result)],
                            ],
                        ],
                    ];
                }

                return [
                    'role' => 'user',
                    'content' => $content,
                ];
            }

            if ($message instanceof UserMessage) {
                $content = [
                    ['text' => $message->content],
                ];

                // Add document attachments
                foreach ($message->attachments as $attachment) {
                    if ($attachment instanceof Document) {
                        $content[] = [
                            'document' => [
                                'format' => $this->getDocumentFormat($attachment),
                                'name' => $attachment->name ?? 'document',
                                'source' => [
                                    'bytes' => $this->getDocumentBytes($attachment),
                                ],
                            ],
                        ];
                    }
                }

                return [
                    'role' => 'user',
                    'content' => $content,
                ];
            }

            if ($message instanceof Message) {
                $role = $message->role->value === 'assistant' ? 'assistant' : 'user';

                return [
                    'role' => $role,
                    'content' => [
                        ['text' => $message->content],
                    ],
                ];
            }

            return [
                'role' => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => [
                    ['text' => $message['content']],
                ],
            ];
        })->all();
    }

    /**
     * Get the document format for Bedrock.
     */
    protected function getDocumentFormat(Document $document): string
    {
        $mime = $document->mime ?? 'text/plain';

        return match (true) {
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'csv') => 'csv',
            str_contains($mime, 'doc') => 'doc',
            str_contains($mime, 'docx') => 'docx',
            str_contains($mime, 'xls') => 'xls',
            str_contains($mime, 'xlsx') => 'xlsx',
            str_contains($mime, 'html') => 'html',
            str_contains($mime, 'markdown') => 'md',
            default => 'txt',
        };
    }

    /**
     * Get the document bytes for Bedrock.
     */
    protected function getDocumentBytes(Document $document): string
    {
        if ($document->path) {
            return file_get_contents($document->path);
        }

        if ($document->content) {
            return $document->content;
        }

        throw new \RuntimeException('Document has no content or path.');
    }

    /**
     * Format tools for Bedrock Converse API.
     *
     * @param  Tool[]  $tools
     */
    protected function formatTools(array $tools): array
    {
        return (new Collection($tools))->map(function ($tool) {
            if (! $tool instanceof Tool) {
                return null;
            }

            $toolName = method_exists($tool, 'name')
                ? $tool->name()
                : class_basename($tool);

            $schema = $tool->schema(new JsonSchemaTypeFactory);

            return [
                'toolSpec' => [
                    'name' => $toolName,
                    'description' => (string) $tool->description(),
                    'inputSchema' => [
                        'json' => (new ObjectSchema($schema))->toArray(),
                    ],
                ],
            ];
        })->filter()->values()->all();
    }

    /**
     * Execute tools and return results.
     *
     * @param  Tool[]  $tools
     * @param  ToolCall[]  $toolCalls
     * @return ToolResult[]
     */
    protected function executeTools(array $tools, array $toolCalls): array
    {
        $toolsByName = (new Collection($tools))->keyBy(function ($tool) {
            return method_exists($tool, 'name') ? $tool->name() : class_basename($tool);
        });

        $results = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $toolsByName->get($toolCall->name);

            if (! $tool) {
                $results[] = new ToolResult(
                    $toolCall->id,
                    $toolCall->name,
                    $toolCall->arguments,
                    'Error: Tool "'.$toolCall->name.'" not found.'
                );

                continue;
            }

            // Invoke callbacks
            call_user_func($this->invokingToolCallback, $tool, $toolCall->arguments);

            try {
                $result = (string) $tool->handle(new ToolRequest($toolCall->arguments));

                call_user_func($this->toolInvokedCallback, $tool, $toolCall->arguments, $result);
            } catch (Throwable $e) {
                // Return error as tool result instead of crashing the entire request
                $result = 'Error executing tool: '.$e->getMessage();
            }

            $results[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $result
            );
        }

        return $results;
    }
}
