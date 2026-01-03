<?php

namespace Laravel\Ai\Files;

abstract class Audio extends File
{
    /**
     * Create a new audio from Base64 data.
     */
    public static function fromBase64(string $base64, ?string $mime = null): Base64Audio
    {
        return new Base64Audio($base64, $mime);
    }

    /**
     * Create a new audio using the audio at the given path.
     */
    public static function fromPath(string $path, ?string $mime = null): LocalAudio
    {
        return new LocalAudio($path, $mime);
    }

    /**
     * Create a new remote audio using the audio at the given URL.
     */
    public static function fromUrl(string $url, ?string $mime = null): RemoteAudio
    {
        return new RemoteAudio($url, $mime);
    }

    /**
     * Create a new stored audio using the audio at the given path on the given disk.
     */
    public static function fromStorage(string $path, ?string $disk = null): StoredAudio
    {
        return new StoredAudio($path, $disk);
    }
}
