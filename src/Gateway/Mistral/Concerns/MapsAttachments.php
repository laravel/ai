<?php

namespace Laravel\Ai\Gateway\Mistral\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredImage;

trait MapsAttachments
{
    /**
     * Map the given Laravel attachments to Chat Completions content parts.
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
                $attachment instanceof Base64Image => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.$attachment->mime.';base64,'.$attachment->base64],
                ],
                $attachment instanceof RemoteImage => [
                    'type' => 'image_url',
                    'image_url' => ['url' => $attachment->url],
                ],
                $attachment instanceof LocalImage => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.($attachment->mimeType() ?? 'image/png').';base64,'.base64_encode(file_get_contents($attachment->path))],
                ],
                $attachment instanceof StoredImage => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.($attachment->mimeType() ?? 'image/png').';base64,'.base64_encode(
                        Storage::disk($attachment->disk)->get($attachment->path)
                    )],
                ],
                $attachment instanceof UploadedFile && $this->isImage($attachment) => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.$attachment->getClientMimeType().';base64,'.base64_encode($attachment->get())],
                ],
                $attachment instanceof RemoteDocument => [
                    'type' => 'document_url',
                    'document_url' => $attachment->url,
                    'document_name' => $attachment->name ?? basename($attachment->url),
                ],
                default => throw new InvalidArgumentException(
                    'Mistral only supports image attachments and remote document URLs. Unsupported attachment type ['.get_class($attachment).'].'
                ),
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
