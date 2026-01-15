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

    public function test_timeout_can_be_passed_to_image_generation(): void
    {
        $response = Image::of('Donut sitting on a kitchen counter.')
            ->timeout(120)
            ->generate(provider: ['xai']);

        $this->assertEquals($response->meta->provider, 'xai');
    }

    public function test_timeout_can_be_passed_directly_to_generate(): void
    {
        $response = Image::of('Donut sitting on a kitchen counter.')
            ->generate(provider: ['xai'], timeout: 120);

        $this->assertEquals($response->meta->provider, 'xai');
    }
}
