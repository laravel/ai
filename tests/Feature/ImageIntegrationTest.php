<?php

use Laravel\Ai\Image;

uses()->group('integration');

test('images can be generated', function () {
    $response = Image::of('Donut sitting on a kitchen counter.')->generate(provider: ['xai']);

    expect($response->meta->provider)->toEqual('xai');
});
