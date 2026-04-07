<?php

namespace Tests\Feature\Providers\Anthropic;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Feature\Agents\AssistantAgent;

class ErrorHandlingTest extends AnthropicTestCase
{
    public function test_http_error_response_throws_request_exception(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'max_tokens: must be at least 1',
                ],
            ], 400),
        ]);

        $this->expectException(RequestException::class);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );
    }

    public function test_rate_limit_response_throws_rate_limited_exception(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        $this->expectException(RateLimitedException::class);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );
    }

    public function test_error_in_200_response_throws_ai_exception(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'type' => 'error',
                'error' => [
                    'type' => 'api_error',
                    'message' => 'Internal server error',
                ],
            ], 200),
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('api_error');

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'anthropic',
        );
    }
}
