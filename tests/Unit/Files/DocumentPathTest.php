<?php

use Laravel\Ai\Files\LocalDocument;
use Laravel\Ai\Files\StoredDocument;

test('local document rejects empty path', function () {
    new LocalDocument('');
})->throws(InvalidArgumentException::class, 'Document file path cannot be empty.');

test('local document rejects whitespace-only path', function () {
    new LocalDocument("  \t\n");
})->throws(InvalidArgumentException::class, 'Document file path cannot be empty.');

test('stored document rejects empty path', function () {
    new StoredDocument('');
})->throws(InvalidArgumentException::class, 'Document file path cannot be empty.');

test('stored document rejects whitespace-only path', function () {
    new StoredDocument("  \t\n");
})->throws(InvalidArgumentException::class, 'Document file path cannot be empty.');
