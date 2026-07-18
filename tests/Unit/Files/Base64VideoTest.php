<?php

use Laravel\Ai\Files\Base64Video;

test('base64 video rejects empty content', function (): void {
    new Base64Video('');
})->throws(InvalidArgumentException::class, 'Base64 video content cannot be empty.');
