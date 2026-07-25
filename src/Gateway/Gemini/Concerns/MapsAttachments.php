<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Base64Video;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\LocalVideo;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Files\StoredVideo;

trait MapsAttachments
{
    /**
     * Map the given Laravel attachments to Gemini content parts.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        return $attachments->map(fn ($attachment): array => $this->mapAttachment($attachment))->all();
    }

    /**
     * Map a Laravel attachment to a Gemini content part.
     */
    protected function mapAttachment(mixed $attachment): array
    {
        if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
            throw new InvalidArgumentException(
                'Unsupported attachment type ['.get_debug_type($attachment).']'
            );
        }

        return match (true) {
            $attachment instanceof ProviderImage => [
                'fileData' => [
                    'fileUri' => $attachment->id,
                ],
            ],
            $attachment instanceof Base64Image => [
                'inlineData' => [
                    'mimeType' => $attachment->mime,
                    'data' => $attachment->base64,
                ],
            ],
            $attachment instanceof RemoteImage => [
                'fileData' => array_filter([
                    'mimeType' => $attachment->mime,
                    'fileUri' => $attachment->url,
                ]),
            ],
            $attachment instanceof LocalImage => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'image/png',
                    'data' => base64_encode(file_get_contents($attachment->path)),
                ],
            ],
            $attachment instanceof StoredImage => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'image/png',
                    'data' => base64_encode(
                        (string) Storage::disk($attachment->disk)->get($attachment->path)
                    ),
                ],
            ],
            $attachment instanceof ProviderDocument => [
                'fileData' => [
                    'fileUri' => $attachment->id,
                ],
            ],
            $attachment instanceof Base64Document => [
                'inlineData' => [
                    'mimeType' => $attachment->mime,
                    'data' => $attachment->base64,
                ],
            ],
            $attachment instanceof LocalDocument => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'application/octet-stream',
                    'data' => base64_encode(file_get_contents($attachment->path)),
                ],
            ],
            $attachment instanceof RemoteDocument => [
                'fileData' => array_filter([
                    'mimeType' => $attachment->mime,
                    'fileUri' => $attachment->url,
                ]),
            ],
            $attachment instanceof StoredDocument => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'application/octet-stream',
                    'data' => base64_encode(
                        (string) Storage::disk($attachment->disk)->get($attachment->path)
                    ),
                ],
            ],
            $attachment instanceof Base64Audio => [
                'inlineData' => [
                    'mimeType' => $attachment->mime ?? 'audio/mp3',
                    'data' => $attachment->base64,
                ],
            ],
            $attachment instanceof LocalAudio => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'audio/mp3',
                    'data' => base64_encode(file_get_contents($attachment->path)),
                ],
            ],
            $attachment instanceof StoredAudio => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'audio/mp3',
                    'data' => base64_encode(
                        (string) Storage::disk($attachment->disk)->get($attachment->path)
                    ),
                ],
            ],
            $attachment instanceof RemoteAudio => [
                'fileData' => array_filter([
                    'mimeType' => $attachment->mime,
                    'fileUri' => $attachment->url,
                ]),
            ],
            $attachment instanceof Base64Video => [
                'inlineData' => [
                    'mimeType' => $attachment->mime ?? 'video/mp4',
                    'data' => $attachment->base64,
                ],
            ],
            $attachment instanceof LocalVideo => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'video/mp4',
                    'data' => base64_encode(file_get_contents($attachment->path)),
                ],
            ],
            $attachment instanceof StoredVideo => [
                'inlineData' => [
                    'mimeType' => $attachment->mimeType() ?? 'video/mp4',
                    'data' => base64_encode(
                        (string) Storage::disk($attachment->disk)->get($attachment->path)
                    ),
                ],
            ],
            $attachment instanceof RemoteVideo => [
                'fileData' => array_filter([
                    'mimeType' => $attachment->mime,
                    'fileUri' => $attachment->url,
                ]),
            ],
            $attachment instanceof UploadedFile => [
                'inlineData' => [
                    'mimeType' => $attachment->getClientMimeType(),
                    'data' => base64_encode($attachment->get()),
                ],
            ],
            default => throw new InvalidArgumentException('Unsupported attachment type ['.get_debug_type($attachment).']'),
        };
    }
}
