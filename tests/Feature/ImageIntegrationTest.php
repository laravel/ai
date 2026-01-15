<?php

namespace Tests\Feature;

use Laravel\Ai\Image;
use Tests\TestCase;

class ImageIntegrationTest extends TestCase
{
    public function test_images_can_be_generated(): void
    {
        $response = Image::of('Donut sitting on a kitchen counter.')->generate(provider: ['xai']);

        $this->assertEquals($response->meta->provider, 'xai');
    }
}
