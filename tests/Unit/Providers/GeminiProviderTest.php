<?php

namespace Tests\Unit\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Providers\GeminiProvider;
use PHPUnit\Framework\TestCase;

class GeminiProviderTest extends TestCase
{
    public function test_it_preserves_provider_specific_aspect_ratios(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame([
            'image_size' => '2K',
            'aspect_ratio' => '3:4',
        ], $provider->defaultImageOptions('3:4', 'medium'));
    }

    public function test_it_keeps_existing_image_option_mapping(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame([
            'image_size' => '1K',
            'aspect_ratio' => '1:1',
        ], $provider->defaultImageOptions('1:1', 'low'));
    }

    protected function makeProvider(): GeminiProvider
    {
        return new GeminiProvider(
            $this->createMock(Gateway::class),
            [
                'name' => 'gemini',
                'driver' => 'gemini',
                'key' => 'test-key',
            ],
            $this->createMock(Dispatcher::class),
        );
    }
}
