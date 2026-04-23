<?php

use Laravel\Ai\Image;

test('images can be generated', function () {
    requiresApiKey('XAI_API_KEY');

    $response = Image::of('Donut sitting on a kitchen counter.')->generate(provider: ['xai']);

    expect($response->meta->provider)->toEqual('xai');
});
