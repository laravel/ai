<?php

namespace Laravel\Ai\Files;

use Illuminate\Http\UploadedFile;

abstract class Video extends File
{
    /**
     * Create a new video from Base64 data.
     */
    public static function fromBase64(string $base64, ?string $mimeType = null): Base64Video
    {
        return new Base64Video($base64, $mimeType);
    }

    /**
     * Create a new video using the video at the given path.
     */
    public static function fromPath(string $path, ?string $mimeType = null): LocalVideo
    {
        return new LocalVideo($path, $mimeType);
    }

    /**
     * Create a new remote video using the video at the given URL.
     */
    public static function fromUrl(string $url, ?string $mimeType = null): RemoteVideo
    {
        return new RemoteVideo($url, $mimeType);
    }

    /**
     * Create a new stored video using the video at the given path on the given disk.
     */
    public static function fromStorage(string $path, ?string $disk = null): StoredVideo
    {
        return new StoredVideo($path, $disk);
    }

    /**
     * Create a new Base64 video using the given file upload.
     */
    public static function fromUpload(UploadedFile $file, ?string $mimeType = null): Base64Video
    {
        return (new Base64Video(
            base64_encode($file->getContent()),
            $mimeType ?? $file->getClientMimeType(),
        ))->as($file->getClientOriginalName());
    }
}
