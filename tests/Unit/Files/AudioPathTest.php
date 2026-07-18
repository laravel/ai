<?php

use Laravel\Ai\Files\LocalAudio;
use Laravel\Ai\Files\StoredAudio;

test('local audio rejects empty path', function (): void {
    new LocalAudio('');
})->throws(InvalidArgumentException::class, 'Audio file path cannot be empty.');

test('local audio rejects whitespace-only path', function (): void {
    new LocalAudio("  \t\n");
})->throws(InvalidArgumentException::class, 'Audio file path cannot be empty.');

test('stored audio rejects empty path', function (): void {
    new StoredAudio('');
})->throws(InvalidArgumentException::class, 'Audio file path cannot be empty.');

test('stored audio rejects whitespace-only path', function (): void {
    new StoredAudio("  \t\n");
})->throws(InvalidArgumentException::class, 'Audio file path cannot be empty.');
