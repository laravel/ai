<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        return $attachments->map(function (File|UploadedFile $attachment): array {
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
                            (string) Storage::disk($attachment->disk)->get($attachment->path)
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
                    'source' => $this->documentSource(
                        $attachment->mime,
                        fn (): string => base64_decode($attachment->base64),
                        fn (): string => $attachment->base64,
                    ),
                ],
                $attachment instanceof LocalDocument => [
                    'type' => 'document',
                    'source' => $this->documentSource(
                        $attachment->mimeType(),
                        fn (): string|false => file_get_contents($attachment->path),
                        fn (): string => base64_encode(file_get_contents($attachment->path)),
                    ),
                ],
                $attachment instanceof RemoteDocument => [
                    'type' => 'document',
                    'source' => $this->remoteDocumentSource($attachment),
                ],
                $attachment instanceof StoredDocument => [
                    'type' => 'document',
                    'source' => $this->documentSource(
                        $attachment->mimeType(),
                        fn () => Storage::disk($attachment->disk)->get($attachment->path),
                        fn (): string => base64_encode((string) Storage::disk($attachment->disk)->get($attachment->path)),
                    ),
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
                    'source' => $this->documentSource(
                        $attachment->getClientMimeType(),
                        fn () => $attachment->get(),
                        fn (): string => base64_encode($attachment->get()),
                    ),
                ],
                default => throw new InvalidArgumentException('Unsupported attachment type ['.$attachment::class.']'),
            };

            if (($mapped['type'] ?? '') === 'document' && $attachment instanceof File && filled($attachment->name())) {
                $mapped['title'] = $attachment->name();
            }

            return $mapped;
        })->all();
    }

    /**
     * Build the Anthropic document `source` block for the given mime type.
     *
     * @param  callable():string  $rawResolver
     * @param  callable():string  $base64Resolver
     * @return array<string, string>
     */
    protected function documentSource(?string $mimeType, callable $rawResolver, callable $base64Resolver): array
    {
        if ($this->normalizeMimeType($mimeType) === 'application/pdf') {
            return [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => $base64Resolver(),
            ];
        }

        $raw = (string) $rawResolver();

        if (str_starts_with($raw, '%PDF-')) {
            return [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => base64_encode($raw),
            ];
        }

        if (! mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
            throw new InvalidArgumentException('Anthropic only accepts PDF or plain text documents; ['.($mimeType ?? 'unknown').'] must be converted first.');
        }

        return [
            'type' => 'text',
            'media_type' => 'text/plain',
            'data' => $raw,
        ];
    }

    /**
     * Build the Anthropic document `source` block for the given remote document.
     *
     * @return array<string, string>
     */
    protected function remoteDocumentSource(RemoteDocument $document): array
    {
        $mimeType = $this->normalizeMimeType($document->declaredMimeType());

        // A `url` source is PDF-only, so anything else has to be fetched and inlined.
        $isPdf = $mimeType === null
            ? in_array(strtolower(pathinfo((string) parse_url($document->url, PHP_URL_PATH), PATHINFO_EXTENSION)), ['', 'pdf'], true)
            : $mimeType === 'application/pdf';

        if ($isPdf) {
            return [
                'type' => 'url',
                'url' => $document->url,
            ];
        }

        return $this->documentSource(
            $document->mimeType(),
            fn (): string => $document->content(),
            fn (): string => base64_encode($document->content()),
        );
    }

    /**
     * Strip any parameters from the given mime type.
     */
    protected function normalizeMimeType(?string $mimeType): ?string
    {
        return blank($mimeType) ? null : strtolower(trim(Str::before($mimeType, ';')));
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
}
