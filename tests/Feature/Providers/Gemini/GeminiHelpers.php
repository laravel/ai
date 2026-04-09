<?php

namespace Tests\Feature\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

trait GeminiHelpers
{
    protected function fakeTextResponse(string $text = 'Hello'): PromiseInterface
    {
        return Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => $text]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
            'modelVersion' => 'gemini-3-flash-preview',
        ]);
    }

    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator', ?string $callId = null): PromiseInterface
    {
        return Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'id' => $callId ?? 'call_123',
                            'name' => $toolName,
                            'args' => (object) [],
                        ],
                    ]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
            'modelVersion' => 'gemini-3-flash-preview',
        ]);
    }

    protected function fakeStructuredResponse(array $data): PromiseInterface
    {
        return Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => json_encode($data)]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 5,
                'totalTokenCount' => 15,
            ],
            'modelVersion' => 'gemini-3-flash-preview',
        ]);
    }
}
