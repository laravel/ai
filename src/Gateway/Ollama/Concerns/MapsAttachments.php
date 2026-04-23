<?php

namespace Laravel\Ai\Gateway\Ollama\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredImage;

trait MapsAttachments
{
    /**
     * Map the given Laravel attachments to Ollama base64 image strings.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        return $attachments->map(function ($attachment) {
            if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
                throw new InvalidArgumentException(
                    'Unsupported attachment type ['.get_class($attachment).']'
                );
            }

            return match (true) {
                $attachment instanceof Base64Image => $attachment->base64,
                $attachment instanceof LocalImage => base64_encode(file_get_contents($attachment->path)),
                $attachment instanceof StoredImage => base64_encode(
                    Storage::disk($attachment->disk)->get($attachment->path)
                ),
                $attachment instanceof UploadedFile && $this->isImage($attachment) => base64_encode($attachment->get()),
                $attachment instanceof RemoteImage => throw new InvalidArgumentException('Ollama does not support remote image URLs. Use a local or base64 image instead.'),
                default => throw new InvalidArgumentException('Ollama does not support document attachments. Only image attachments are supported.'),
            };
        })->all();
    }

    /**
     * Determine if the given uploaded file is an image.
     */
    protected function isImage(UploadedFile $attachment): bool
    {
        return in_array($attachment->getClientMimeType(), [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ]);
    }
}
