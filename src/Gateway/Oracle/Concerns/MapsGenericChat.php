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
 * Maps Laravel AI messages/tools to OCI's GenericChatRequest (apiFormat = GENERIC) and
 * parses GenericChatResponse payloads. Used for Meta Llama, xAI Grok, and other non-Cohere models.
 */
trait MapsGenericChat
{
    /**
     * Build the OCI generic message list from Laravel AI messages and system instructions.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildGenericMessages(array $messages, ?string $instructions): array
    {
        $formatted = [];

        if (filled($instructions)) {
            $formatted[] = $this->genericMessage('SYSTEM', $instructions);
        }

        foreach ($messages as $message) {
            $formatted = array_merge($formatted, $this->formatGenericMessage($message));
        }

        return $formatted;
    }

    /**
     * Format a single Laravel AI message into one or more OCI generic messages.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function formatGenericMessage(mixed $message): array
    {
        return match (true) {
            $message instanceof AssistantMessage => [$this->genericAssistantMessage($message->content, $message->toolCalls->all())],
            $message instanceof ToolResultMessage => $this->genericToolResultMessages($message->toolResults->all()),
            $message instanceof UserMessage => [$this->genericMessage('USER', $message->content)],
            $message instanceof Message => [$this->genericMessage(
                $message->role === MessageRole::Assistant ? 'ASSISTANT' : 'USER',
                $message->content,
            )],
            default => [$this->genericMessage(
                ($message['role'] ?? '') === MessageRole::Assistant->value ? 'ASSISTANT' : 'USER',
                $message['content'] ?? '',
            )],
        };
    }

    /**
     * Build a simple single-text-part generic message.
     *
     * @return array<string, mixed>
     */
    protected function genericMessage(string $role, string $text): array
    {
        return [
            'role' => $role,
            'content' => [['type' => 'TEXT', 'text' => $text]],
        ];
    }

    /**
     * Build a generic ASSISTANT message carrying optional text and tool calls.
     *
     * @param  array<ToolCall>  $toolCalls
     * @return array<string, mixed>
     */
    protected function genericAssistantMessage(string $text, array $toolCalls): array
    {
        $message = ['role' => 'ASSISTANT'];

        $message['content'] = $text !== '' ? [['type' => 'TEXT', 'text' => $text]] : [];

        if (! empty($toolCalls)) {
            $message['toolCalls'] = array_map(fn (ToolCall $toolCall) => [
                'id' => $toolCall->id,
                'type' => 'FUNCTION',
                'name' => $toolCall->name,
                'arguments' => json_encode($toolCall->arguments ?: (object) []),
            ], $toolCalls);
        }

        return $message;
    }

    /**
     * Build the TOOL-role generic messages carrying tool results.
     *
     * @param  array<ToolResult>  $toolResults
     * @return array<int, array<string, mixed>>
     */
    protected function genericToolResultMessages(array $toolResults): array
    {
        return array_map(fn (ToolResult $toolResult) => [
            'role' => 'TOOL',
            'toolCallId' => $toolResult->id,
            'content' => [['type' => 'TEXT', 'text' => is_string($toolResult->result) ? $toolResult->result : json_encode($toolResult->result)]],
        ], $toolResults);
    }

    /**
     * Format Laravel AI tools as OCI generic FUNCTION tool definitions.
     *
     * @param  array<Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function formatGenericTools(array $tools): array
    {
        return (new Collection($tools))
            ->filter(fn ($tool) => $tool instanceof Tool)
            ->map(fn (Tool $tool) => [
                'type' => 'FUNCTION',
                'function' => [
                    'name' => ToolNameResolver::resolve($tool),
                    'description' => (string) $tool->description(),
                    'parameters' => (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toArray(),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * Build the synthetic structured-output tool plus any real tools for the generic format.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function buildGenericSchemaTools(array $schema, array $tools): array
    {
        return array_merge([
            [
                'type' => 'FUNCTION',
                'function' => [
                    'name' => self::STRUCTURED_OUTPUT_TOOL,
                    'description' => 'Return the response as a structured JSON object matching the provided schema.',
                    'parameters' => (new ObjectSchema($schema))->toArray(),
                ],
            ],
        ], $this->formatGenericTools($tools));
    }

    /**
     * Parse a GenericChatResponse into normalized text, tool calls, finish reason, and usage.
     *
     * @param  array<string, mixed>  $chatResponse
     * @return array{text: string, toolCalls: array<ToolCall>, finishReason: FinishReason, usage: Usage}
     */
    protected function parseGenericResponse(array $chatResponse): array
    {
        $choice = $chatResponse['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $text = '';

        foreach ($message['content'] ?? [] as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        $toolCalls = [];

        foreach ($message['toolCalls'] ?? [] as $call) {
            $toolCalls[] = new ToolCall(
                $call['id'] ?? (string) Str::uuid(),
                $call['name'] ?? '',
                $this->decodeArguments($call['arguments'] ?? null),
            );
        }

        return [
            'text' => $text,
            'toolCalls' => $toolCalls,
            'finishReason' => $this->genericFinishReason($choice['finishReason'] ?? ''),
            'usage' => $this->extractUsage($chatResponse),
        ];
    }

    /**
     * Map an OCI generic finish reason to the Laravel AI finish reason enum.
     */
    protected function genericFinishReason(string $reason): FinishReason
    {
        return match (strtolower($reason)) {
            'stop', 'complete' => FinishReason::Stop,
            'tool_calls' => FinishReason::ToolCalls,
            'length', 'max_tokens' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            '' => FinishReason::Unknown,
            default => FinishReason::Unknown,
        };
    }

    /**
     * Decode tool-call arguments which OCI returns as a JSON-encoded string.
     *
     * @return array<string, mixed>
     */
    protected function decodeArguments(mixed $arguments): array
    {
        if (is_object($arguments)) {
            $arguments = json_decode((string) json_encode($arguments), true);
        }

        if (is_array($arguments)) {
            return $arguments;
        }

        if (! is_string($arguments) || $arguments === '') {
            return [];
        }

        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extract token usage from an OCI chat response (shared shape across families).
     *
     * @param  array<string, mixed>  $chatResponse
     */
    protected function extractUsage(array $chatResponse): Usage
    {
        $usage = $chatResponse['usage'] ?? [];

        return new Usage(
            promptTokens: $usage['promptTokens'] ?? 0,
            completionTokens: $usage['completionTokens'] ?? 0,
        );
    }
}
