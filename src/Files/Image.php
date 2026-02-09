<?php

namespace Laravel\Ai\Files;

use Illuminate\Http\UploadedFile;

abstract class Image extends File
{
    /**
     * Create a new image from Base64 data.
     */
    public static function fromBase64(string $base64, ?string $mime = null): Base64Image
    {
        return new Base64Image($base64, $mime);
    }

    /**
     * Create a new provider image using the image with the given ID.
     */
    public static function fromId(string $id): ProviderImage
    {
        return new ProviderImage($id);
    }

    /**
     * Create a new image using the image at the given path.
     */
    public static function fromPath(string $path, ?string $mime = null): LocalImage
    {
        return new LocalImage($path, $mime);
    }

    /**
     * Create a new remote image using the image at the given URL.
     */
    public static function fromUrl(string $url): RemoteImage
    {
        return new RemoteImage($url);
    }

    /**
     * Create a new stored image using the image at the given path on the given disk.
     */
    public static function fromStorage(string $path, ?string $disk = null): StoredImage
    {
        return new StoredImage($path, $disk);
    }

    /**
     * Create a new Base64 image using the given file upload.
     */
    public static function fromUpload(UploadedFile $file, ?string $mime = null): Base64Image
    {
        return new Base64Image(
            base64_encode($file->getContent()),
            $mime ?? $file->getClientMimeType(),
        )->as($file->getClientOriginalName());
    }
}
