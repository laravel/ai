<?php

use Laravel\Ai\Files\Base64Image;

test('with mime type rejects blank value', function () {
    (new Base64Image(base64_encode('image-bytes')))->withMimeType('');
})->throws(InvalidArgumentException::class, 'MIME type cannot be blank.');

test('with mime type rejects whitespace-only value', function () {
    (new Base64Image(base64_encode('image-bytes')))->withMimeType("  \t\n");
})->throws(InvalidArgumentException::class, 'MIME type cannot be blank.');
