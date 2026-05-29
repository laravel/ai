<?php

namespace Laravel\Ai\Gateway\Oracle\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Maps Laravel AI messages/tools to OCI's CohereChatRequest (apiFormat = COHERE) and
 * parses CohereChatResponse payloads. Used for the cohere.command-* model family.
 */
trait MapsCohereChat
{
    /**
     * Build the Cohere request state (current message, chat history, preamble) from Laravel AI messages.
     *
     * Cohere takes the latest user turn as `message` and all prior turns as `chatHistory`; the
     * system instructions are mapped to `preambleOverride`.
     *
     * @return array{message: string, chatHistory: array<int, array<string, string>>, preamble: ?string}
     */
    protected function buildCohereState(array $messages, ?string $instructions): array
    {
        $turns = [];

        foreach ($messages as $message) {
            $turn = $this->cohereTurn($message);

            if ($turn !== null) {
                $turns[] = $turn;
            }
        }

        $message = '';

        for ($i = count($turns) - 1; $i >= 0; $i--) {
            if ($turns[$i]['role'] === 'USER') {
                $message = $turns[$i]['message'];

                array_splice($turns, $i, 1);

                break;
            }
        }

        return [
            'message' => $message,
            'chatHistory' => $turns,
            'preamble' => filled($instructions) ? $instructions : null,
        ];
    }

    /**
     * Convert a single Laravel AI message into a Cohere chat-history turn (or null to skip).
     *
     * @return array{role: string, message: string}|null
     */
    protected function cohereTurn(mixed $message): ?array
    {
        return match (true) {
            $message instanceof UserMessage => ['role' => 'USER', 'message' => $message->content],
            $message instanceof AssistantMessage => $message->content !== ''
                ? ['role' => 'CHATBOT', 'message' => $message->content]
                : null,
            $message instanceof ToolResultMessage => null,
            $message instanceof Message => [
                'role' => $message->role === MessageRole::Assistant ? 'CHATBOT' : 'USER',
                'message' => $message->content,
            ],
            is_array($message) => [
                'role' => ($message['role'] ?? '') === MessageRole::Assistant->value ? 'CHATBOT' : 'USER',
                'message' => $message['content'] ?? '',
            ],
            default => null,
        };
    }

    /**
     * Format Laravel AI tools as Cohere tool definitions.
     *
     * @param  array<Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function formatCohereTools(array $tools): array
    {
        return (new Collection($tools))
            ->filter(fn ($tool) => $tool instanceof Tool)
            ->map(fn (Tool $tool) => [
                'name' => ToolNameResolver::resolve($tool),
                'description' => (string) $tool->description(),
                'parameterDefinitions' => $this->schemaToParameterDefinitions(
                    (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toArray()
                ),
            ])
            ->values()
            ->all();
    }

    /**
     * Build the synthetic structured-output tool for the Cohere format.
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, array<string, mixed>>
     */
    protected function buildCohereSchemaTool(array $schema): array
    {
        return [
            [
                'name' => self::STRUCTURED_OUTPUT_TOOL,
                'description' => 'Return the response as a structured JSON object matching the provided schema.',
                'parameterDefinitions' => $this->schemaToParameterDefinitions($schema),
            ],
        ];
    }

    /**
     * Convert a JSON schema object into Cohere parameterDefinitions.
     *
     * TODO: nested object/array property types are flattened to their JSON type; deep schemas
     * may need richer mapping once exercised against a live tenancy.
     *
     * @param  array<string, mixed>  $jsonSchema
     * @return array<string, array<string, mixed>>
     */
    protected function schemaToParameterDefinitions(array $jsonSchema): array
    {
        $properties = $jsonSchema['properties'] ?? [];
        $required = $jsonSchema['required'] ?? [];

        $definitions = [];

        foreach ($properties as $name => $definition) {
            $definitions[$name] = [
                'description' => (string) ($definition['description'] ?? ''),
                'type' => $definition['type'] ?? 'string',
                'isRequired' => in_array($name, $required, true),
            ];
        }

        return $definitions;
    }

    /**
     * Build Cohere toolResults from executed tool results paired with their originating calls.
     *
     * @param  array<ToolResult>  $toolResults
     * @return array<int, array<string, mixed>>
     */
    protected function buildCohereToolResults(array $toolResults): array
    {
        return array_map(fn (ToolResult $toolResult) => [
            'call' => [
                'name' => $toolResult->name,
                'parameters' => (object) $toolResult->arguments,
            ],
            'outputs' => [
                is_array($toolResult->result) ? $toolResult->result : ['output' => (string) $toolResult->result],
            ],
        ], $toolResults);
    }

    /**
     * Parse a CohereChatResponse into normalized text, tool calls, finish reason, and usage.
     *
     * @param  array<string, mixed>  $chatResponse
     * @return array{text: string, toolCalls: array<ToolCall>, finishReason: FinishReason, usage: Usage}
     */
    protected function parseCohereResponse(array $chatResponse): array
    {
        $toolCalls = [];

        foreach ($chatResponse['toolCalls'] ?? [] as $call) {
            $toolCalls[] = new ToolCall(
                $call['id'] ?? (string) Str::uuid(),
                $call['name'] ?? '',
                $call['parameters'] ?? [],
            );
        }

        return [
            'text' => $chatResponse['text'] ?? '',
            'toolCalls' => $toolCalls,
            'finishReason' => $this->cohereFinishReason($chatResponse['finishReason'] ?? ''),
            'usage' => $this->extractUsage($chatResponse),
        ];
    }

    /**
     * Map an OCI Cohere finish reason to the Laravel AI finish reason enum.
     */
    protected function cohereFinishReason(string $reason): FinishReason
    {
        return match (strtoupper($reason)) {
            'COMPLETE' => FinishReason::Stop,
            'MAX_TOKENS' => FinishReason::Length,
            'ERROR_TOXIC' => FinishReason::ContentFilter,
            'ERROR', 'ERROR_LIMIT', 'USER_CANCEL' => FinishReason::Error,
            default => FinishReason::Unknown,
        };
    }
}
