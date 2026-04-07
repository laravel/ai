<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;

trait MapsAttachments
{
    /**
     * Map the given Laravel attachments to Anthropic content blocks.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        return $attachments->map(function (File|UploadedFile $attachment) {
            $mapped = match (true) {
                $attachment instanceof ProviderImage => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'file',
                        'file_id' => $attachment->id,
                    ],
                ],
                $attachment instanceof Base64Image => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->mime,
                        'data' => $attachment->base64,
                    ],
                ],
                $attachment instanceof RemoteImage => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'url',
                        'url' => $attachment->url,
                    ],
                ],
                $attachment instanceof LocalImage => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->mimeType(),
                        'data' => base64_encode(file_get_contents($attachment->path)),
                    ],
                ],
                $attachment instanceof StoredImage => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->mimeType(),
                        'data' => base64_encode(
                            Storage::disk($attachment->disk)->get($attachment->path)
                        ),
                    ],
                ],
                $attachment instanceof ProviderDocument => [
                    'type' => 'document',
                    'source' => [
                        'type' => 'file',
                        'file_id' => $attachment->id,
                    ],
                ],
                $attachment instanceof Base64Document => [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->mime,
                        'data' => $attachment->base64,
                    ],
                ],
                $attachment instanceof LocalDocument => [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->mimeType(),
                        'data' => base64_encode(file_get_contents($attachment->path)),
                    ],
                ],
                $attachment instanceof RemoteDocument => [
                    'type' => 'document',
                    'source' => [
                        'type' => 'url',
                        'url' => $attachment->url,
                    ],
                ],
                $attachment instanceof StoredDocument => [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->mimeType(),
                        'data' => base64_encode(
                            Storage::disk($attachment->disk)->get($attachment->path)
                        ),
                    ],
                ],
                $attachment instanceof UploadedFile && $this->isImage($attachment) => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->getClientMimeType(),
                        'data' => base64_encode($attachment->get()),
                    ],
                ],
                $attachment instanceof UploadedFile => [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->getClientMimeType(),
                        'data' => base64_encode($attachment->get()),
                    ],
                ],
                default => throw new InvalidArgumentException('Unsupported attachment type ['.get_class($attachment).']'),
            };

            if (($mapped['type'] ?? '') === 'document' && $attachment instanceof File && filled($attachment->name)) {
                $mapped['title'] = $attachment->name;
            }

            return $mapped;
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
