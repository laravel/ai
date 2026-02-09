<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Tests\TestCase;

class FromUploadMimeTest extends TestCase
{
    public function test_image_from_upload_uses_file_mime_type_by_default(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg');

        $image = Image::fromUpload($file);

        $this->assertEquals($file->getClientMimeType(), $image->mimeType());
    }

    public function test_image_from_upload_uses_provided_mime_type(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg');

        $image = Image::fromUpload($file, 'image/webp');

        $this->assertEquals('image/webp', $image->mimeType());
    }

    public function test_document_from_upload_uses_file_mime_type_by_default(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $document = Document::fromUpload($file);

        $this->assertEquals('application/pdf', $document->mimeType());
    }

    public function test_document_from_upload_uses_provided_mime_type(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $document = Document::fromUpload($file, 'application/octet-stream');

        $this->assertEquals('application/octet-stream', $document->mimeType());
    }

    public function test_image_from_upload_preserves_original_name(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg');

        $image = Image::fromUpload($file, 'image/webp');

        $this->assertEquals('photo.jpg', $image->name());
    }

    public function test_document_from_upload_preserves_original_name(): void
    {
        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        $document = Document::fromUpload($file, 'text/plain');

        $this->assertEquals('report.pdf', $document->name());
    }
}
