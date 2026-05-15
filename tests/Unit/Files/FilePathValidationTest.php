<?php

use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;

test('local document rejects blank path', function () {
    new LocalDocument('');
})->throws(InvalidArgumentException::class, 'Document file path cannot be empty.');

test('local document rejects whitespace-only path', function () {
    new LocalDocument(" \t");
})->throws(InvalidArgumentException::class, 'Document file path cannot be empty.');

test('stored document rejects blank path', function () {
    new StoredDocument('');
})->throws(InvalidArgumentException::class, 'Document file path cannot be empty.');

test('local image rejects blank path', function () {
    new LocalImage('');
})->throws(InvalidArgumentException::class, 'Image file path cannot be empty.');

test('stored image rejects blank path', function () {
    new StoredImage('');
})->throws(InvalidArgumentException::class, 'Image file path cannot be empty.');

test('local audio rejects blank path', function () {
    new LocalAudio('');
})->throws(InvalidArgumentException::class, 'Audio file path cannot be empty.');

test('stored audio rejects blank path', function () {
    new StoredAudio('');
})->throws(InvalidArgumentException::class, 'Audio file path cannot be empty.');
