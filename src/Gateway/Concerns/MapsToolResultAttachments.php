<?php

namespace Laravel\Ai\Gateway\Concerns;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Responses\Data\ToolResult;

trait MapsToolResultAttachments
{
    /**
     * Map a tool result to content parts: its text, followed by the files it returned.
     *
     * A file the provider can't receive is noted in the text instead, since the tool has already run...
     *
     * @param  Closure(string): array  $text  Maps text to a content part.
     * @param  Closure(Image|Document): array  $attachment  Maps a file to a content part, throwing an InvalidArgumentException when the provider can't receive it.
     * @return array<int, array>
     */
    protected function mapToolResultParts(ToolResult $toolResult, Closure $text, Closure $attachment): array
    {
        $parts = [];
        $notes = [];

        foreach ($toolResult->attachments as $file) {
            try {
                $parts[] = $attachment($file);
            } catch (InvalidArgumentException) {
                $notes[] = $toolResult->attachmentNote($file);
            }
        }

        $body = implode("\n", array_filter(
            [$toolResult->resultText(), ...$notes],
            fn (string $value) => $value !== '',
        ));

        return [...($body === '' ? [] : [$text($body)]), ...$parts];
    }

    /**
     * Throw for an image in a format the provider can't take in tool results, so it's noted instead.
     *
     * An image sent inline needs one of the given types, exactly as it's sent. A remote or provider image is sent as a
     * reference, so it's only checked when it declares a type, rather than fetched to find out.
     *
     * @param  list<string>  $mimeTypes
     */
    protected function ensureToolResultImageType(Image|Document $file, array $mimeTypes): void
    {
        if (! $file instanceof Image) {
            return;
        }

        $isReference = $file instanceof RemoteImage || $file instanceof ProviderImage;
        $mime = $isReference ? $file->mime : $file->mimeType();

        if ($isReference && $mime === null) {
            return;
        }

        if (! in_array($mime, $mimeTypes, true)) {
            throw new InvalidArgumentException('Images in tool results can\'t be ['.($mime ?? 'unknown').'].');
        }
    }
}
