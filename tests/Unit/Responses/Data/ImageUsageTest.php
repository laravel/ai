<?php

use Laravel\Ai\Responses\Data\ImageUsage;

test('image usage to array appends the image token counts to the text usage counts', function (): void {
    $usage = new ImageUsage(100, 50, 10, null, 5, 8, 40);

    expect($usage->toArray())->toBe([
        'input_tokens' => 100,
        'output_tokens' => 50,
        'cache_read_input_tokens' => 10,
        'cache_write_input_tokens' => null,
        'reasoning_tokens' => 5,
        'image_input_tokens' => 8,
        'image_output_tokens' => 40,
    ]);
});
