<?php

namespace Laravel\Ai\Files;

use Illuminate\Http\UploadedFile;

abstract class Document extends File
{
    /**
     * Create a new document from a string.
     */
    public static function fromString(string $content, ?string $mimeType = null): Base64Document
    {
        return new Base64Document(base64_encode($content), $mimeType);
    }

    /**
     * Create a new document from Base64 data.
     */
    public static function fromBase64(string $base64, ?string $mimeType = null): Base64Document
    {
        return new Base64Document($base64, $mimeType);
    }

    /**
     * Create a new provider document using the document with the given ID.
     */
    public static function fromId(string $id): ProviderDocument
    {
        return new ProviderDocument($id);
    }

    /**
     * Create a new document using the document at the given path.
     */
    public static function fromPath(string $path): LocalDocument
    {
        return new LocalDocument($path);
    }

    /**
     * Create a new remote document using the document at the given URL.
     */
    public static function fromUrl(string $url): RemoteDocument
    {
        return new RemoteDocument($url);
    }

    /**
     * Create a new stored document using the document at the given path on the given disk.
     */
    public static function fromStorage(string $path, ?string $disk = null): StoredDocument
    {
        return new StoredDocument($path, $disk);
    }

    /**
     * Create a new Base64 document using the given file upload.
     */
    public static function fromUpload(UploadedFile $file, ?string $mimeType = null): Base64Document
    {
        return new Base64Document(
            base64_encode($file->getContent()),
            $mimeType ?? $file->getClientMimeType(),
        )->as($file->getClientOriginalName());
    }
}
