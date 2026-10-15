<?php

namespace Laravel\Ai\Gateway\Concerns;

use finfo;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Contracts\Files\StorableFile;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;

trait ResolvesInlineImages
{
    /**
     * Resolve an attachment to an image with its content and MIME type, or null when it has no inline content.
     *
     * @return array{Image&StorableFile, string, string}|null
     */
    protected function resolveInlineImage(File|UploadedFile $image): ?array
    {
        if ($image instanceof UploadedFile) {
            $image = Image::fromUpload($image);
        }

        if (! $image instanceof Image || ! $image instanceof StorableFile) {
            return null;
        }

        $content = $image->content();

        return [$image, $content, $image->mimeType() ?? (new finfo(FILEINFO_MIME_TYPE))->buffer($content)];
    }
}
