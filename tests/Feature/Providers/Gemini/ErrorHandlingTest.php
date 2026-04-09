<?php

namespace Tests\Feature\Providers\Gemini;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Feature\Agents\AssistantAgent;

class ErrorHandlingTest extends GeminiTestCase
{
    public function test_http_error_response_throws_request_exception(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'Invalid value at contents',
                    'status' => 'INVALID_ARGUMENT',
                ],
            ], 400),
        ]);

        $this->expectException(RequestException::class);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );
    }

    public function test_rate_limit_response_throws_rate_limited_exception(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 429,
                    'message' => 'Resource has been exhausted',
                    'status' => 'RESOURCE_EXHAUSTED',
                ],
            ], 429),
        ]);

        $this->expectException(RateLimitedException::class);

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );
    }

    public function test_error_in_200_response_throws_ai_exception(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 'internal',
                    'message' => 'Internal server error',
                ],
            ], 200),
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Gemini Error');

        (new AssistantAgent)->prompt(
            'Hi',
            provider: 'gemini',
        );
    }
}
