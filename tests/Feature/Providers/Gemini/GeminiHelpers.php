<?php

namespace Tests\Feature\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

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
            'modelVersion' => 'gemini-3.7-flash',
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
            'modelVersion' => 'gemini-3.7-flash',
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
            'modelVersion' => 'gemini-3.7-flash',
        ]);
    }

    protected function fakeUniqueToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'id' => 'call_'.uniqid(),
                            'name' => 'FixedNumberGenerator',
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
            'modelVersion' => 'gemini-3.7-flash',
        ]);
    }

    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'gemini',
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        return $events;
    }

    protected function ssePayload(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = 'data: '.json_encode($event);
        }

        return implode("\n\n", $lines)."\n\n";
    }

    protected function geminiChunk(array $parts, ?string $modelVersion = null, ?string $finishReason = null): array
    {
        $candidate = [
            'content' => [
                'parts' => $parts,
                'role' => 'model',
            ],
        ];

        if ($finishReason !== null) {
            $candidate['finishReason'] = $finishReason;
        }

        return [
            'candidates' => [$candidate],
            'modelVersion' => $modelVersion ?? 'gemini-3.7-flash',
        ];
    }

    protected function geminiChunkWithUsage(array $parts, int $promptTokens, int $candidatesTokens, int $cachedTokens = 0, ?string $modelVersion = null, string $finishReason = 'STOP'): array
    {
        $chunk = $this->geminiChunk($parts, $modelVersion, $finishReason);

        $chunk['usageMetadata'] = array_filter([
            'promptTokenCount' => $promptTokens,
            'candidatesTokenCount' => $candidatesTokens,
            'totalTokenCount' => $promptTokens + $candidatesTokens,
            'cachedContentTokenCount' => $cachedTokens ?: null,
        ]);

        return $chunk;
    }
}
