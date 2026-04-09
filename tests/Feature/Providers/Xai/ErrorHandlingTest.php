<?php

namespace Tests\Feature\Providers\Xai;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Feature\Agents\AssistantAgent;

class ErrorHandlingTest extends XaiTestCase
{
    public function test_http_error_response_throws_request_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'Invalid API key',
                ],
            ], 401),
        ]);

        $this->expectException(RequestException::class);

        (new AssistantAgent)->prompt('Hi', provider: 'xai');
    }

    public function test_rate_limit_response_throws_rate_limited_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'type' => 'rate_limit_error',
                    'message' => 'Rate limit exceeded',
                ],
            ], 429),
        ]);

        $this->expectException(RateLimitedException::class);

        (new AssistantAgent)->prompt('Hi', provider: 'xai');
    }

    public function test_error_in_200_response_throws_ai_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'type' => 'server_error',
                    'message' => 'Internal server error',
                ],
            ], 200),
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('xAI Error');

        (new AssistantAgent)->prompt('Hi', provider: 'xai');
    }

    public function test_failed_status_response_throws_ai_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => 'resp_123',
                'object' => 'response',
                'status' => 'failed',
                'error' => [
                    'code' => 'server_error',
                    'message' => 'The response failed.',
                ],
                'output' => [],
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ], 200),
        ]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('The response failed.');

        (new AssistantAgent)->prompt('Hi', provider: 'xai');
    }
}
