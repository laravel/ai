<?php

namespace Laravel\Ai\Vercel;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\ConversationMessage;

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

        return new Chat(is_array($messages) ? array_values($messages) : iterator_to_array($messages, false));
    }

    /**
     * Convert messages or conversation message models into UI message arrays for hydrating useChat.
     *
     * @param  iterable<int, Message|ConversationMessage>  $messages
     * @return list<array<string, mixed>>
     */
    public static function uiMessagesFrom(iterable $messages): array
    {
        $result = [];

        // ponytail: text-only hydration, file and tool parts when a UI needs them...
        foreach ($messages as $message) {
            $role = $message instanceof Message ? $message->role->value : $message->role;

            if (! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $result[] = [
                'id' => $message instanceof ConversationMessage ? $message->id : (string) Str::ulid(),
                'role' => $role,
                'parts' => [['type' => 'text', 'text' => $message->content ?? '']],
            ];
        }

        return $result;
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

        foreach ($messages as $message) {
            $result[] = static::messageFrom($message);
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
            'assistant' => new AssistantMessage($text),
            default => throw new InvalidArgumentException('Invalid message role.'),
        };
    }

    /**
     * Create a file instance from a Vercel AI SDK UI message file part.
     *
     * @param  array<string, mixed>  $part
     */
    protected static function fileFrom(array $part): ?File
    {
        $url = $part['url'] ?? '';
        $mime = strtolower($part['mediaType'] ?? '');

        $file = match (true) {
            blank($url) => null,
            str_starts_with($url, 'data:') => static::fileFromDataUrl($url, $mime),
            str_starts_with($mime, 'image/') => new RemoteImage($url, $mime),
            str_starts_with($mime, 'audio/') => new RemoteAudio($url, $mime),
            default => new RemoteDocument($url, $mime ?: null),
        };

        return $file?->as($part['filename'] ?? null);
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
            default => new Base64Document($base64, $mime ?: null),
        };
    }
}
