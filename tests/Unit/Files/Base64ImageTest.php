<?php

use Laravel\Ai\Files\Base64Image;

test('base64 image rejects empty content', function () {
    new Base64Image('');
})->throws(InvalidArgumentException::class, 'Base64 image content cannot be empty.');
