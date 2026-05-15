<?php

use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Base64Image;

test('base64 image rejects empty content', function () {
    new Base64Image('');
})->throws(InvalidArgumentException::class, 'Base64 image content cannot be empty.');

test('base64 image rejects whitespace-only content', function () {
    new Base64Image("  \t\n");
})->throws(InvalidArgumentException::class, 'Base64 image content cannot be empty.');

test('base64 document rejects empty content', function () {
    new Base64Document('');
})->throws(InvalidArgumentException::class, 'Base64 document content cannot be empty.');

test('base64 document rejects whitespace-only content', function () {
    new Base64Document("  \t\n");
})->throws(InvalidArgumentException::class, 'Base64 document content cannot be empty.');
