<?php

use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Tools\ToolResponse;

test('a tool response is its text as a string', function (): void {
    $response = ToolResponse::make('Here is the chart.', [Image::fromStorage('charts/q3.png')]);

    expect((string) $response)->toBe('Here is the chart.')
        ->and($response->attachments)->toHaveCount(1);
});

test('a tool response only attaches images and documents', function (): void {
    ToolResponse::make('Listen.', [Audio::fromStorage('clips/q3.mp3')]);
})->throws(InvalidArgumentException::class, 'Tool responses can only attach images and documents, [Laravel\Ai\Files\StoredAudio] given.');
