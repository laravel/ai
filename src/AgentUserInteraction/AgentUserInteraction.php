<?php

namespace Laravel\Ai\AgentUserInteraction;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * The Agent User Interaction (AG-UI) protocol's input side.
 *
 * See: https://docs.ag-ui.com/concepts/messages
 */
class AgentUserInteraction
{
    /**
     * Create a chat instance from a RunAgentInput request or its array representation.
     *
     * @param  Request|array<string, mixed>  $input
     */
    public static function chat(Request|array $input): Chat
    {
        return new Chat($input instanceof Request ? $input->all() : $input);
    }

    /**
     * Create messages from a list of AG-UI messages.
     *
     * @param  iterable<int, mixed>  $messages
     * @return list<Message>
     */
    public static function fromMessages(iterable $messages): array
    {
        $result = [];
        $calls = [];
        $toolResults = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = $message['role'] ?? null;

            // Tool results are buffered so parallel calls settle within a single tool result message...
            if ($role === 'tool') {
                if (($toolResult = static::toolResultFrom($message, $calls)) !== null) {
                    $toolResults[] = $toolResult;
                }

                continue;
            }

            $result = static::flushToolResults($result, $toolResults);
            $toolResults = [];

            // System and developer messages are skipped since agent instructions are not client supplied...
            if (! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $result[] = $converted = static::fromMessage($message);

            if ($converted instanceof AssistantMessage) {
                foreach ($converted->toolCalls as $call) {
                    $calls[$call->id] = $call;
                }
            }
        }

        return static::flushToolResults($result, $toolResults);
    }

    /**
     * Create a message from a single AG-UI message.
     *
     * @param  array<string, mixed>  $message
     */
    public static function fromMessage(array $message): Message
    {
        $content = $message['content'] ?? null;

        return match ($message['role'] ?? null) {
            'user' => new UserMessage(static::textFrom($content), static::attachmentsFrom($content)),
            'assistant' => new AssistantMessage(static::textFrom($content), static::toolCallsFrom($message)),
            'tool' => new ToolResultMessage(new Collection(array_filter([static::toolResultFrom($message)]))),
            default => throw new InvalidArgumentException('Invalid message role.'),
        };
    }

    /**
     * Create approval decisions from a RunAgentInput's resume entries.
     *
     * @param  iterable<int, mixed>  $resume
     */
    public static function decisionsFrom(iterable $resume): ?Decisions
    {
        $decisions = [];

        // Malformed entries are skipped, and repeated interrupt IDs collapse so a replayed resume is idempotent...
        foreach ($resume as $entry) {
            if (! is_array($entry) || ! is_string($id = $entry['interruptId'] ?? null) || blank($id)) {
                continue;
            }

            $status = $entry['status'] ?? null;

            if ($status === 'cancelled') {
                $decisions[$id] = Decision::reject();

                continue;
            }

            if ($status === 'resolved' && is_bool($approved = $entry['payload']['approved'] ?? null)) {
                $decisions[$id] = $approved;
            }
        }

        return $decisions === [] ? null : Decisions::from($decisions);
    }

    /**
     * Convert messages or conversation message models into state for hydrating an AG-UI client.
     *
     * @param  iterable<int, Message|ConversationMessage>  $messages
     * @return array{messages: list<array<string, mixed>>, interrupts: list<array<string, mixed>>}
     */
    public static function toClientState(iterable $messages): array
    {
        $messages = [...$messages];

        return [
            'messages' => static::toMessages($messages),
            'interrupts' => static::toInterrupts($messages),
        ];
    }

    /**
     * Convert messages or conversation message models into AG-UI message arrays for hydrating a client.
     *
     * @param  iterable<int, Message|ConversationMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public static function toMessages(iterable $messages): array
    {
        $result = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $toolResult) {
                    $result[] = static::toolMessageFrom($toolResult->toArray());
                }

                continue;
            }

            $role = $message instanceof Message ? $message->role->value : $message->role;

            if (! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $id = $message instanceof ConversationMessage ? $message->id : (string) Str::ulid();

            if ($role === 'user') {
                $result[] = ['id' => $id, 'role' => 'user', 'content' => static::hydratedContent($message)];

                continue;
            }

            $toolCalls = static::hydratedToolCalls($message);
            $ownResults = new Collection;

            if ($message instanceof ConversationMessage) {
                $toolCallIds = array_column($toolCalls, 'id');

                [$ownResults, $priorResults] = (new Collection($message->tool_results ?? []))->partition(
                    fn (array $toolResult) => in_array($toolResult['id'] ?? null, $toolCallIds, true)
                );

                foreach ($priorResults as $toolResult) {
                    $result[] = static::toolMessageFrom($toolResult, $id);
                }
            }

            if (filled($message->content) || $toolCalls !== []) {
                $result[] = [
                    'id' => $id,
                    'role' => 'assistant',
                    ...(filled($message->content) ? ['content' => $message->content] : []),
                    ...($toolCalls !== [] ? ['toolCalls' => $toolCalls] : []),
                ];
            }

            foreach ($ownResults as $toolResult) {
                $result[] = static::toolMessageFrom($toolResult, $id);
            }
        }

        return $result;
    }

    /**
     * Get the open interrupts held by stored conversation messages.
     *
     * @param  iterable<int, Message|ConversationMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public static function toInterrupts(iterable $messages): array
    {
        $interrupts = [];

        foreach ($messages as $message) {
            if (! $message instanceof ConversationMessage) {
                continue;
            }

            foreach (($message->approval_state ?? [])['pending'] ?? [] as $callId => $reason) {
                $interrupts[] = static::interrupt((string) $callId, is_string($reason) ? $reason : null);
            }
        }

        return $interrupts;
    }

    /**
     * Get the interrupt that represents a pending tool approval.
     *
     * @return array<string, mixed>
     */
    public static function interrupt(string $id, ?string $reason = null): array
    {
        return [
            'id' => $id,
            'reason' => 'tool_call',
            ...(filled($reason) ? ['message' => $reason] : []),
            'toolCallId' => $id,
            'responseSchema' => [
                'type' => 'object',
                'properties' => ['approved' => ['type' => 'boolean']],
                'required' => ['approved'],
            ],
        ];
    }

    /**
     * Get the AG-UI tool message that represents a stored tool result.
     *
     * @param  array<string, mixed>  $toolResult
     * @return array<string, mixed>
     */
    protected static function toolMessageFrom(array $toolResult, ?string $messageId = null): array
    {
        $denied = ($toolResult['denied'] ?? false) === true;

        $content = match (true) {
            $denied => 'The tool call was denied.',
            is_string($toolResult['result'] ?? null) => $toolResult['result'],
            default => static::json($toolResult['result'] ?? null),
        };

        return [
            'id' => $toolResult['result_id'] ?? $messageId.'-'.$toolResult['id'],
            'role' => 'tool',
            'toolCallId' => $toolResult['id'],
            'content' => $content,
            ...($denied ? ['error' => $content] : []),
        ];
    }

    /**
     * Get the AG-UI content that represents a stored message's text and attachments.
     *
     * @return string|list<array<string, mixed>>
     */
    protected static function hydratedContent(Message|ConversationMessage $message): string|array
    {
        $parts = static::attachmentPartsFrom($message);

        if ($parts === []) {
            return (string) $message->content;
        }

        return [
            ...(filled($message->content) ? [['type' => 'text', 'text' => $message->content]] : []),
            ...$parts,
        ];
    }

    /**
     * Get the AG-UI content parts that represent a stored message's attachments.
     *
     * @return list<array<string, mixed>>
     */
    protected static function attachmentPartsFrom(Message|ConversationMessage $message): array
    {
        $attachments = match (true) {
            $message instanceof UserMessage => $message->attachments,
            $message instanceof ConversationMessage => (new Collection($message->attachments ?? []))
                ->map(fn ($attachment) => is_array($attachment) ? File::fromArray($attachment) : null)
                ->filter(),
            default => new Collection,
        };

        return $attachments
            ->map(fn (File $file) => static::contentPartFrom($file))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the AG-UI content part that represents the given file.
     *
     * @return array<string, mixed>|null
     */
    protected static function contentPartFrom(File $file): ?array
    {
        $mime = rescue(fn () => method_exists($file, 'declaredMimeType')
            ? $file->declaredMimeType()
            : $file->mimeType(), report: false);

        $source = rescue(fn () => match (true) {
            isset($file->url) => ['type' => 'url', 'value' => $file->url],
            isset($file->base64) => ['type' => 'data', 'value' => $file->base64],
            $file instanceof StorableFile => ['type' => 'data', 'value' => base64_encode($file->content())],
            default => null,
        }, report: false);

        if ($source === null) {
            return null;
        }

        return [
            'type' => match (true) {
                $file instanceof Image => 'image',
                $file instanceof Audio => 'audio',
                $file instanceof Video => 'video',
                default => 'document',
            },
            'source' => [...$source, ...(filled($mime) ? ['mimeType' => $mime] : [])],
            ...(filled($file->name()) ? ['metadata' => ['filename' => $file->name()]] : []),
        ];
    }

    /**
     * Get the AG-UI tool calls that represent a stored message's tool calls.
     *
     * @return list<array<string, mixed>>
     */
    protected static function hydratedToolCalls(Message|ConversationMessage $message): array
    {
        $calls = match (true) {
            $message instanceof AssistantMessage => $message->toolCalls->map(fn (ToolCall $call) => $call->toArray())->all(),
            $message instanceof ConversationMessage => $message->tool_calls ?? [],
            default => [],
        };

        return array_values(array_map(fn (array $call) => [
            'id' => $call['id'],
            'type' => 'function',
            'function' => [
                'name' => $call['name'],
                'arguments' => static::json((object) ($call['arguments'] ?? [])),
            ],
            ...(is_string($call['reasoning_encrypted_content'] ?? null)
                ? ['encryptedValue' => $call['reasoning_encrypted_content']]
                : []),
        ], $calls));
    }

    /**
     * Append a tool result message for the given buffered tool results.
     *
     * @param  list<Message>  $messages
     * @param  list<ToolResult>  $toolResults
     * @return list<Message>
     */
    protected static function flushToolResults(array $messages, array $toolResults): array
    {
        return $toolResults === []
            ? $messages
            : [...$messages, new ToolResultMessage(new Collection($toolResults))];
    }

    /**
     * Create a tool result from an AG-UI tool message, naming it from the tool call it settles.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, ToolCall>  $calls
     */
    protected static function toolResultFrom(array $message, array $calls = []): ?ToolResult
    {
        if (! is_string($id = $message['toolCallId'] ?? null) || blank($id)) {
            return null;
        }

        $call = $calls[$id] ?? null;

        $content = $message['content'] ?? null;
        $error = $message['error'] ?? null;

        return new ToolResult(
            id: $id,
            name: $call?->name ?? '',
            arguments: $call?->arguments ?? [],
            result: filled($error) ? $error : $content,
            resultId: is_string($message['id'] ?? null) ? $message['id'] : null,
        );
    }

    /**
     * Get the tool calls declared on an AG-UI assistant message.
     *
     * @param  array<string, mixed>  $message
     * @return Collection<int, ToolCall>
     */
    protected static function toolCallsFrom(array $message): Collection
    {
        return (new Collection($message['toolCalls'] ?? []))
            ->filter(fn ($call) => is_array($call) && is_string($call['id'] ?? null) && is_string($call['function']['name'] ?? null))
            ->map(fn (array $call) => new ToolCall(
                id: $call['id'],
                name: $call['function']['name'],
                arguments: static::arguments($call['function']['arguments'] ?? null),
                reasoningEncryptedContent: is_string($call['encryptedValue'] ?? null) ? $call['encryptedValue'] : null,
            ))
            ->values();
    }

    /**
     * Decode an AG-UI tool call's JSON encoded arguments.
     *
     * @return array<string, mixed>
     */
    protected static function arguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        return is_string($arguments) ? (array) json_decode($arguments, true) : [];
    }

    /**
     * Get the text held by AG-UI message content.
     */
    protected static function textFrom(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return (new Collection(is_array($content) ? $content : []))
            ->filter(fn ($part) => is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null))
            ->pluck('text')
            ->implode(PHP_EOL.PHP_EOL);
    }

    /**
     * Get the attachments held by AG-UI message content.
     *
     * @return Collection<int, File>
     */
    protected static function attachmentsFrom(mixed $content): Collection
    {
        return (new Collection(is_array($content) ? $content : []))
            ->filter(fn ($part) => is_array($part) && in_array($part['type'] ?? null, ['image', 'audio', 'video', 'document'], true))
            ->map(fn (array $part) => static::fileFrom($part))
            ->filter()
            ->values();
    }

    /**
     * Create a file instance from an AG-UI multimodal content part.
     *
     * @param  array<string, mixed>  $part
     */
    protected static function fileFrom(array $part): ?File
    {
        $source = $part['source'] ?? [];
        $value = is_array($source) ? $source['value'] ?? null : null;
        $mime = is_array($source) ? $source['mimeType'] ?? null : null;

        // Malformed client parts are skipped rather than failing the request...
        if (! is_string($value) || blank($value) || ! (is_string($mime) || $mime === null)) {
            return null;
        }

        $mime = $mime === null ? null : strtolower($mime);
        $sourceType = $source['type'] ?? null;

        if (! in_array($sourceType, ['url', 'data'], true) || ($sourceType === 'data' && blank($mime))) {
            return null;
        }

        $remote = $sourceType === 'url';

        $file = match ($part['type']) {
            'image' => $remote ? new RemoteImage($value, $mime) : new Base64Image($value, $mime),
            'audio' => $remote ? new RemoteAudio($value, $mime) : new Base64Audio($value, $mime),
            'video' => $remote ? new RemoteVideo($value, $mime) : new Base64Video($value, $mime),
            default => $remote ? new RemoteDocument($value, $mime) : new Base64Document($value, $mime),
        };

        $filename = $part['metadata']['filename'] ?? null;

        return $file->as(is_string($filename) ? $filename : null);
    }

    /**
     * Encode the given value as JSON, substituting bytes a provider streamed as invalid UTF-8.
     */
    protected static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
