<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;

trait MapsUploadedFiles
{
    protected function uploadedImageAsDataUri(UploadedFile $file): string
    {
        return Image::fromUpload($file)->asDataUri();
    }

    protected function uploadedFileAsDataUri(UploadedFile $file): string
    {
        return Document::fromUpload($file)->asDataUri();
    }
}
