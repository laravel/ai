<?php

namespace Laravel\Ai\Gateway\Bedrock\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\ProviderImage;

trait MapsAttachment
{
    /**
     * Map the given Laravel attachments to Bedrock content blocks.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        return $attachments->map(function (File|UploadedFile $attachment) {
            return match (true) {
                $attachment instanceof Document => [
                    'document' => [
                        'format' => $this->getDocumentFormat($attachment),
                        'name' => $this->getDocumentName($attachment),
                        'source' => [
                            'bytes' => $attachment->content()
                        ]
                    ]
                ],
                $attachment instanceof Base64Image => [
                    'image' => [
                        'format' => $this->getImageFormat($attachment),
                        'name' => $attachment->name(),
                        'source' => [
                            'bytes' => $attachment->content()
                        ]
                    ]
                ]
            };
        })->all();
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
     * Build a unique, Bedrock-compliant image name.
     *
     * Bedrock requires image names to be unique within a message and limits
     * them to alphanumerics, whitespace, hyphens, parentheses, and square brackets.
     */
    protected function getImageName(Image $image): string
    {
        $name = $image->name() ?? 'image';
        $name = pathinfo($name, PATHINFO_FILENAME) ?: $name;
        $name = preg_replace('/[^A-Za-z0-9\-\(\)\[\] ]+/', '-', $name);

        return trim(preg_replace('/\s+/', ' ', $name)) ?: 'image';
    }

    /**
     * Map an Image's MIME type to a Bedrock image format.
     *
     * Bedrock supports JPEG, PNG, GIF, and WebP images.
     *
     * @throws InvalidArgumentException if the image is stored by a provider or has an unsupported MIME type.
     */
    protected function getImageFormat(Image $image): string
    {
        if ($image instanceof ProviderImage) {
            throw new InvalidArgumentException('Provider-stored images are not supported as attachments for Bedrock.');
        }

        $mime = $image->mimeType();

        if (!$mime) {
            throw new InvalidArgumentException('Unable to determine MIME type for image ['.$image->name().'].');
        }

        return match (strtolower(trim($mime))) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => throw new InvalidArgumentException('Unsupported image MIME type ['.$mime.']'),
        };
    }
}
