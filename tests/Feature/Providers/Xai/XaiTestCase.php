<?php

namespace Tests\Feature\Providers\Xai;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class XaiTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.xai' => [
            ...config('ai.providers.xai'),
            'key' => 'test-key',
        ]]);
    }

    protected function fakeTextResponse(string $text = 'Hello'): PromiseInterface
    {
        return Http::response([
            'id' => 'resp_123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => [
                [
                    'type' => 'message',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'output_text', 'text' => $text, 'annotations' => []],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ]);
    }

    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator', ?string $callId = null): PromiseInterface
    {
        $callId ??= 'call_123';

        return Http::response([
            'id' => 'resp_tool_'.uniqid(),
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => [
                [
                    'type' => 'function_call',
                    'id' => 'fc_'.uniqid(),
                    'call_id' => $callId,
                    'name' => $toolName,
                    'arguments' => '{}',
                    'status' => 'completed',
                ],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
                'input_tokens_details' => ['cached_tokens' => 0],
                'output_tokens_details' => ['reasoning_tokens' => 0],
            ],
        ]);
    }

    protected function fakeStructuredResponse(string $json = '{"symbol": "Au"}'): PromiseInterface
    {
        return $this->fakeTextResponse($json);
    }
}
