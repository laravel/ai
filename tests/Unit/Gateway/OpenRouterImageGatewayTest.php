<?php

namespace Tests\Unit\Gateway;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Gateway\OpenAi\OpenAiGateway;
use Laravel\Ai\Gateway\OpenRouterImageGateway;
use Laravel\Ai\Providers\OpenRouterProvider;
use Laravel\Ai\Responses\ImageResponse;
use Tests\TestCase;

class OpenRouterImageGatewayTest extends TestCase
{
    protected function provider(): OpenRouterProvider
    {
        return new OpenRouterProvider(
            new OpenAiGateway($this->app->make('events')),
            [
                'name' => 'openrouter',
                'driver' => 'openrouter',
                'key' => 'test-key',
            ],
            $this->app->make('events'),
        );
    }

    public function test_it_generates_an_image_from_prompt(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'images' => [
                                [
                                    'image_url' => [
                                        'url' => 'data:image/png;base64,'.base64_encode('fake-image-bytes'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'model' => 'google/gemini-2.5-flash-image',
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 20,
                ],
            ]),
        ]);

        $gateway = new OpenRouterImageGateway;

        $response = $gateway->generateImage(
            $this->provider(),
            'google/gemini-2.5-flash-image',
            'A blue circle on white background',
        );

        $this->assertInstanceOf(ImageResponse::class, $response);
        $this->assertCount(1, $response->images);
        $this->assertEquals('fake-image-bytes', $response->firstImage()->content());
        $this->assertEquals('image/png', $response->firstImage()->mime);
        $this->assertEquals('openrouter', $response->meta->provider);
        $this->assertEquals('google/gemini-2.5-flash-image', $response->meta->model);
        $this->assertEquals(10, $response->usage->promptTokens);
        $this->assertEquals(20, $response->usage->completionTokens);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $body['model'] === 'google/gemini-2.5-flash-image'
                && $body['modalities'] === ['image', 'text']
                && $body['messages'][0]['role'] === 'user'
                && $body['messages'][0]['content'] === 'A blue circle on white background';
        });
    }

    public function test_it_passes_image_config_for_aspect_ratio(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'images' => [
                                [
                                    'image_url' => [
                                        'url' => 'data:image/png;base64,'.base64_encode('fake'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $gateway = new OpenRouterImageGateway;

        $gateway->generateImage(
            $this->provider(),
            'google/gemini-2.5-flash-image',
            'A sunset',
            size: '3:2',
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['image_config']['aspect_ratio'])
                && $body['image_config']['aspect_ratio'] === '3:2';
        });
    }

    public function test_it_returns_empty_images_when_no_images_in_response(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'I cannot generate that image.',
                        ],
                    ],
                ],
            ]),
        ]);

        $gateway = new OpenRouterImageGateway;

        $response = $gateway->generateImage(
            $this->provider(),
            'google/gemini-2.5-flash-image',
            'A prompt',
        );

        $this->assertCount(0, $response->images);
    }

    public function test_it_handles_multiple_images_in_response(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'images' => [
                                [
                                    'image_url' => [
                                        'url' => 'data:image/png;base64,'.base64_encode('first'),
                                    ],
                                ],
                                [
                                    'image_url' => [
                                        'url' => 'data:image/jpeg;base64,'.base64_encode('second'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $gateway = new OpenRouterImageGateway;

        $response = $gateway->generateImage(
            $this->provider(),
            'google/gemini-2.5-flash-image',
            'Two images',
        );

        $this->assertCount(2, $response->images);
        $this->assertEquals('first', $response->images[0]->content());
        $this->assertEquals('image/png', $response->images[0]->mime);
        $this->assertEquals('second', $response->images[1]->content());
        $this->assertEquals('image/jpeg', $response->images[1]->mime);
    }
}
