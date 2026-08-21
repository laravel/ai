<?php

namespace Laravel\Ai\Vercel;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

class Vercel
{
    /**
     * Create a chat input from a useChat request or its UI message list.
     *
     * @param  Request|iterable<int, array<string, mixed>>  $input
     */
    public static function chat(Request|iterable $input): Chat
    {
        $messages = $input instanceof Request ? $input->input('messages', []) : $input;

        return new Chat(is_iterable($messages) ? array_values([...$messages]) : []);
    }

    /**
     * Create messages from a list of Vercel AI SDK UI messages (a useChat request body).
     *
     * @param  iterable<int, array<string, mixed>>  $messages
     * @return list<Message>
     */
    public static function messagesFrom(iterable $messages): array
    {
        $result = [];

        // Unknown roles are skipped so a client-supplied payload cannot fail the history path...
        foreach ($messages as $message) {
            if (! in_array($message['role'] ?? null, ['user', 'assistant'], true)) {
                continue;
            }

            $result[] = $converted = static::messageFrom($message);

            if ($converted instanceof AssistantMessage
                && ($toolResults = static::toolResultsFrom($message))->isNotEmpty()) {
                $result[] = new ToolResultMessage($toolResults);
            }
        }

        return $result;
    }

    /**
     * Create a message from a single Vercel AI SDK UI message (parts-based) array.
     *
     * @param  array<string, mixed>  $message
     */
    public static function messageFrom(array $message): Message
    {
        $parts = new Collection($message['parts'] ?? []);

        $text = $parts->where('type', 'text')->pluck('text')->implode(PHP_EOL.PHP_EOL);

        return match ($message['role'] ?? null) {
            'user' => new UserMessage($text, $parts
                ->where('type', 'file')
                ->map(fn (array $part) => static::fileFrom($part))
                ->filter()
                ->values()),
            'assistant' => new AssistantMessage($text, static::toolParts($parts)
                ->map(fn (array $part) => new ToolCall(
                    id: $part['toolCallId'],
                    name: static::toolName($part),
                    arguments: is_array($part['input'] ?? null) ? $part['input'] : [],
                ))
                ->values()),
            default => throw new InvalidArgumentException('Invalid message role.'),
        };
    }

    /**
     * Create tool results from a UI message's settled tool parts.
     *
     * @param  array<string, mixed>  $message
     * @return Collection<int, ToolResult>
     */
    protected static function toolResultsFrom(array $message): Collection
    {
        return static::toolParts($message['parts'] ?? [])
            ->filter(fn (array $part) => in_array($part['state'] ?? null, ['output-available', 'output-error', 'output-denied'], true))
            ->map(fn (array $part) => new ToolResult(
                id: $part['toolCallId'],
                name: static::toolName($part),
                arguments: is_array($part['input'] ?? null) ? $part['input'] : [],
                result: $part['output'] ?? $part['errorText'] ?? null,
                denied: ($part['state'] ?? null) === 'output-denied',
            ))
            ->values();
    }

    /**
     * Get the tool approval responses awaiting execution on a UI message, keyed by tool call id.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, bool>
     */
    public static function approvalResponsesFrom(array $message): array
    {
        // Settled parts may still carry stale approval responses, so only pending states count...
        return static::toolParts($message['parts'] ?? [])
            ->filter(fn (array $part) => in_array($part['state'] ?? null, ['approval-requested', 'approval-responded'], true)
                && is_bool($part['approval']['approved'] ?? null))
            ->mapWithKeys(fn (array $part) => [$part['toolCallId'] => $part['approval']['approved']])
            ->all();
    }

    /**
     * Filter the given parts down to the tool parts.
     *
     * @param  iterable<int, mixed>  $parts
     * @return Collection<int, array<string, mixed>>
     */
    protected static function toolParts(iterable $parts): Collection
    {
        return (new Collection($parts))->filter(fn ($part) => is_array($part)
            && str_starts_with($part['type'] ?? '', 'tool-')
            && isset($part['toolCallId']));
    }

    /**
     * Get the tool name encoded in a UI tool part's type.
     *
     * @param  array<string, mixed>  $part
     */
    protected static function toolName(array $part): string
    {
        return substr($part['type'], strlen('tool-'));
    }

    /**
     * Create a file instance from a Vercel AI SDK UI message file part.
     *
     * @param  array<string, mixed>  $part
     */
    protected static function fileFrom(array $part): ?File
    {
        $url = $part['url'] ?? '';
        $mime = $part['mediaType'] ?? '';

        // Malformed client parts are skipped rather than failing the request...
        if (! is_string($url) || ! is_string($mime)) {
            return null;
        }

        $mime = strtolower($mime);

        $file = match (true) {
            blank($url) => null,
            str_starts_with($url, 'data:') => static::fileFromDataUrl($url, $mime),
            str_starts_with($mime, 'image/') => new RemoteImage($url, $mime),
            str_starts_with($mime, 'audio/') => new RemoteAudio($url, $mime),
            str_starts_with($mime, 'video/') => new RemoteVideo($url, $mime),
            default => new RemoteDocument($url, $mime ?: null),
        };

        $filename = $part['filename'] ?? null;

        return $file?->as(is_string($filename) ? $filename : null);
    }

    /**
     * Create a base64 file instance from a data URL.
     */
    protected static function fileFromDataUrl(string $url, string $mime): ?File
    {
        $mime = $mime ?: strtolower(str_replace('data:', '', strstr($url, ';', true) ?: ''));

        $base64 = str_contains($url, 'base64,') ? substr($url, strpos($url, 'base64,') + 7) : '';

        return match (true) {
            blank($base64) => null,
            str_starts_with($mime, 'image/') => new Base64Image($base64, $mime),
            str_starts_with($mime, 'audio/') => new Base64Audio($base64, $mime),
            str_starts_with($mime, 'video/') => new Base64Video($base64, $mime),
            default => new Base64Document($base64, $mime ?: null),
        };
    }
}
