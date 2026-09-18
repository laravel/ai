<?php

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;

test('embeddings response serializes its usage under a usage key', function (): void {
    $response = new EmbeddingsResponse([[0.1, 0.2]], new Usage(10), new Meta('openai', 'text-embedding-3-small'));

    $serialized = json_decode(json_encode($response), true);

    expect($serialized)->not->toHaveKey('tokens')
        ->and($serialized['usage']['input_tokens'])->toBe(10);
});
