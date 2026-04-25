<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Http\UploadedFile;

trait MapsUploadedFiles
{
    protected function uploadedFileAsDataUri(UploadedFile $file): string
    {
        return 'data:'.$file->getClientMimeType().';base64,'.base64_encode($file->get());
    }
}
