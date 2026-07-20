<?php

namespace Laravel\Ai\Vercel;

use Illuminate\Support\Collection;
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

class Vercel
{
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
