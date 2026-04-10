<?php

namespace Tests\Feature\Providers\Mistral;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Feature\Agents\AssistantAgent;

class ErrorHandlingTest extends MistralTestCase
{
    public function test_http_error_response_throws_request_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'object' => 'error',
                'message' => 'Invalid API key',
                'type' => 'authentication_error',
            ], 401),
        ]);

        $this->expectException(RequestException::class);

        (new AssistantAgent)->prompt('Hi', provider: 'mistral');
    }

    public function test_rate_limit_response_throws_rate_limited_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'object' => 'error',
                'message' => 'Rate limit exceeded',
                'type' => 'rate_limit_error',
            ], 429),
        ]);

        $this->expectException(RateLimitedException::class);

        (new AssistantAgent)->prompt('Hi', provider: 'mistral');
    }

    public function test_overloaded_response_throws_provider_overloaded_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'object' => 'error',
                'message' => 'The server is currently overloaded. Please try again later.',
                'type' => 'server_error',
            ], 503),
        ]);

        $this->expectException(ProviderOverloadedException::class);

        (new AssistantAgent)->prompt('Hi', provider: 'mistral');
    }

    public function test_error_in_200_response_throws_ai_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'object' => 'error',
                'error' => [
                    'type' => 'server_error',
                    'message' => 'Internal server error',
                ],
            ], 200),
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Mistral Error');

        (new AssistantAgent)->prompt('Hi', provider: 'mistral');
    }
}
