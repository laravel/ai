<?php

use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\StoredImage;

test('local image rejects empty path', function () {
    new LocalImage('');
})->throws(InvalidArgumentException::class, 'Image file path cannot be empty.');

test('local image rejects whitespace-only path', function () {
    new LocalImage("  \t\n");
})->throws(InvalidArgumentException::class, 'Image file path cannot be empty.');

test('stored image rejects empty path', function () {
    new StoredImage('');
})->throws(InvalidArgumentException::class, 'Image file path cannot be empty.');

test('stored image rejects whitespace-only path', function () {
    new StoredImage("  \t\n");
})->throws(InvalidArgumentException::class, 'Image file path cannot be empty.');
