<?php

namespace Tests\Unit\Providers;

use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenAiProvider;
use Tests\TestCase;

class OpenAiProviderDefaultImageOptionsTest extends TestCase
{
    public function test_default_image_options_does_not_include_moderation(): void
    {
        $provider = Ai::imageProvider('openai');

        $options = $provider->defaultImageOptions();

        $this->assertArrayNotHasKey('moderation', $options);
    }

    public function test_default_image_options_contains_expected_keys(): void
    {
        $provider = Ai::imageProvider('openai');

        $options = $provider->defaultImageOptions();

        $this->assertArrayHasKey('quality', $options);
        $this->assertArrayHasKey('size', $options);
        $this->assertCount(2, $options);
    }

    public function test_default_image_options_maps_size_correctly(): void
    {
        $provider = Ai::imageProvider('openai');

        $this->assertEquals('1024x1024', $provider->defaultImageOptions('1:1')['size']);
        $this->assertEquals('1024x1536', $provider->defaultImageOptions('2:3')['size']);
        $this->assertEquals('1536x1024', $provider->defaultImageOptions('3:2')['size']);
        $this->assertEquals('auto', $provider->defaultImageOptions()['size']);
        $this->assertEquals('512x512', $provider->defaultImageOptions('512x512')['size']);
    }

    public function test_default_image_options_quality_defaults_to_auto(): void
    {
        $provider = Ai::imageProvider('openai');

        $this->assertEquals('auto', $provider->defaultImageOptions()['quality']);
        $this->assertEquals('hd', $provider->defaultImageOptions(quality: 'hd')['quality']);
    }
}
