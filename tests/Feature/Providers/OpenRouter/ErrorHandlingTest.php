<?php

namespace Tests\Feature\Providers\OpenRouter;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\TestCase;

use function Laravel\Ai\agent;

class ErrorHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openrouter' => [
            ...config('ai.providers.openrouter'),
            'key' => 'test-key',
        ]]);
    }

    public function test_http_error_response_throws_request_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Bad request']], 400)]);

        $this->expectException(RequestException::class);

        agent()->prompt('Hello', provider: 'openrouter');
    }

    public function test_rate_limit_response_throws_rate_limited_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limited']], 429)]);

        $this->expectException(RateLimitedException::class);

        agent()->prompt('Hello', provider: 'openrouter');
    }

    public function test_error_in_200_response_throws_ai_exception(): void
    {
        Http::fake(['*' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'The model does not exist.',
            ],
        ])]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('OpenRouter Error');

        agent()->prompt('Hello', provider: 'openrouter');
    }
}
