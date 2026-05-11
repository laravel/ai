<?php

namespace Laravel\Ai\Gateway\Bedrock\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
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
     * Map the given Laravel attachments to Bedrock content blocks.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        return $attachments->map(function (File|UploadedFile $attachment) {
            return match (true) {
                $attachment instanceof Base64Document,
                $attachment instanceof LocalDocument,
                $attachment instanceof StoredDocument => $this->buildDocumentBlock($attachment),
                $attachment instanceof Base64Image => $this->buildImageBlock($attachment, $attachment->content()),
                $attachment instanceof LocalImage => $this->buildImageBlock($attachment, file_get_contents($attachment->path)),
                $attachment instanceof StoredImage => $this->buildImageBlock(
                    $attachment,
                    Storage::disk($attachment->disk)->get($attachment->path),
                ),
                $attachment instanceof RemoteDocument,
                $attachment instanceof RemoteImage => throw new InvalidArgumentException(
                    'Remote attachments are not supported by Bedrock; download the file and pass it as a Base64, Local, or Stored attachment.'
                ),
                $attachment instanceof ProviderDocument,
                $attachment instanceof ProviderImage => throw new InvalidArgumentException(
                    'Provider-stored attachments are not supported by Bedrock.'
                ),
                default => throw new InvalidArgumentException('Unsupported attachment type ['.get_class($attachment).'].'),
            };
        })->all();
    }

    /**
     * Build a Bedrock document content block.
     */
    protected function buildDocumentBlock(Document $document): array
    {
        return [
            'document' => [
                'format' => $this->getDocumentFormat($document),
                'name' => $this->getDocumentName($document),
                'source' => [
                    'bytes' => $document->content(),
                ],
            ],
        ];
    }

    /**
     * Build a Bedrock image content block.
     */
    protected function buildImageBlock(Image $image, string $bytes): array
    {
        return [
            'image' => [
                'format' => $this->getImageFormat($image),
                'source' => [
                    'bytes' => $bytes,
                ],
            ],
        ];
    }

    /**
     * Map a Document's MIME type to a Bedrock document format.
     */
    protected function getDocumentFormat(Document $document): string
    {
        $mime = strtolower(trim(strtok($document->mimeType() ?? 'text/plain', ';')));

        return match ($mime) {
            'application/pdf' => 'pdf',
            'text/csv' => 'csv',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/html' => 'html',
            'text/markdown', 'text/x-markdown' => 'md',
            default => 'txt',
        };
    }

    /**
     * Build a unique, Bedrock-compliant document name.
     *
     * Bedrock requires document names to be unique within a message and limits
     * them to alphanumerics, whitespace, hyphens, parentheses, and square brackets.
     */
    protected function getDocumentName(Document $document): string
    {
        $name = $document->name() ?? 'document';
        $name = pathinfo($name, PATHINFO_FILENAME) ?: $name;
        $name = preg_replace('/[^A-Za-z0-9\-\(\)\[\] ]+/', '-', $name);

        return trim(preg_replace('/\s+/', ' ', $name)) ?: 'document';
    }

    /**
     * Map an Image's MIME type to a Bedrock image format.
     *
     * Bedrock supports JPEG, PNG, GIF, and WebP images.
     *
     * @throws InvalidArgumentException if the MIME type cannot be determined or is unsupported.
     */
    protected function getImageFormat(Image $image): string
    {
        $mime = $image->mimeType();

        if (! $mime) {
            throw new InvalidArgumentException('Unable to determine MIME type for image ['.$image->name().'].');
        }

        return match (strtolower(trim($mime))) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => throw new InvalidArgumentException('Unsupported image MIME type ['.$mime.'].'),
        };
    }
}
