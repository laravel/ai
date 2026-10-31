<?php

namespace Laravel\Ai\Gateway\OpenRouter\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Base64Audio;
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
use Laravel\Ai\Gateway\Concerns\ResolvesDocumentFilenames;

trait MapsAttachments
{
    use ResolvesDocumentFilenames;

    /**
     * Map the given Laravel attachments to Chat Completions content parts.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        return $attachments->map(function ($attachment): array {
            if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
                throw new InvalidArgumentException(
                    'Unsupported attachment type ['.$attachment::class.']'
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
                        (string) Storage::disk($attachment->disk)->get($attachment->path)
                    )],
                ],
                $attachment instanceof Base64Document => [
                    'type' => 'file',
                    'file' => [
                        'filename' => $attachment->name() ?? $this->fallbackFilename($attachment->mime),
                        'file_data' => 'data:'.$attachment->mime.';base64,'.$attachment->base64,
                    ],
                ],
                $attachment instanceof LocalDocument => [
                    'type' => 'file',
                    'file' => [
                        'filename' => $attachment->name(),
                        'file_data' => 'data:'.($attachment->mimeType() ?? 'application/octet-stream').';base64,'.base64_encode(file_get_contents($attachment->path)),
                    ],
                ],
                $attachment instanceof RemoteDocument => [
                    'type' => 'file',
                    'file' => array_filter([
                        'filename' => $attachment->name(),
                        'file_data' => $attachment->url,
                    ]),
                ],
                $attachment instanceof StoredDocument => [
                    'type' => 'file',
                    'file' => [
                        'filename' => $attachment->name(),
                        'file_data' => 'data:'.($attachment->mimeType() ?? 'application/octet-stream').';base64,'.base64_encode(
                            (string) Storage::disk($attachment->disk)->get($attachment->path)
                        ),
                    ],
                ],
                $attachment instanceof Base64Audio => [
                    'type' => 'input_audio',
                    'input_audio' => [
                        'format' => $this->audioFormat($attachment->mime ?? 'audio/mp3'),
                        'data' => $attachment->base64,
                    ],
                ],
                $attachment instanceof Audio && $attachment instanceof StorableFile => [
                    'type' => 'input_audio',
                    'input_audio' => [
                        'format' => $this->audioFormat($attachment->mimeType() ?? 'audio/mp3'),
                        'data' => base64_encode($attachment->content()),
                    ],
                ],
                $attachment instanceof UploadedFile && $this->isImage($attachment) => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.$attachment->getClientMimeType().';base64,'.base64_encode($attachment->get())],
                ],
                $attachment instanceof UploadedFile && $this->isAudio($attachment) => [
                    'type' => 'input_audio',
                    'input_audio' => [
                        'format' => $this->audioFormat($attachment->getClientMimeType()),
                        'data' => base64_encode($attachment->get()),
                    ],
                ],
                $attachment instanceof UploadedFile => [
                    'type' => 'file',
                    'file' => [
                        'filename' => $attachment->getClientOriginalName(),
                        'file_data' => 'data:'.$attachment->getClientMimeType().';base64,'.base64_encode($attachment->get()),
                    ],
                ],
                $attachment instanceof ProviderDocument,
                $attachment instanceof ProviderImage => throw new InvalidArgumentException(
                    'Provider-stored attachments are not supported by OpenRouter; uploaded files may only be loaded into a sandbox container by the shell tool.'
                ),
                default => throw new InvalidArgumentException('Unsupported attachment type ['.$attachment::class.']'),
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
        ],
            true);
    }

    /**
     * Determine if the given uploaded file is an audio file.
     */
    protected function isAudio(UploadedFile $attachment): bool
    {
        return str_starts_with($attachment->getClientMimeType(), 'audio/');
    }
}
