<?php

namespace Tests\Feature\Providers\OpenAi;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Feature\Agents\AssistantAgent;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'key' => 'test-key',
        ]]);
    }

    public function test_http_error_response_throws_request_exception(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid request: max_output_tokens must be at least 1',
                ],
            ], 400),
        ]);

        $this->expectException(RequestException::class);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'openai',
        );
    }

    public function test_rate_limit_response_throws_rate_limited_exception(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        $this->expectException(RateLimitedException::class);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'openai',
        );
    }

    public function test_overloaded_response_throws_provider_overloaded_exception(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'The server is currently overloaded. Please try again later.',
                ],
            ], 503),
        ]);

        $this->expectException(ProviderOverloadedException::class);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'openai',
        );
    }

    public function test_error_in_200_response_throws_ai_exception(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'type' => 'api_error',
                    'message' => 'Internal server error',
                ],
            ], 200),
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('OpenAI Error');

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'openai',
        );
    }

    public function test_failed_status_response_throws_ai_exception(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'resp_123',
                'status' => 'failed',
                'model' => 'gpt-5.4',
                'output' => [],
            ], 200),
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('OpenAI Error');

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'openai',
        );
    }
}
