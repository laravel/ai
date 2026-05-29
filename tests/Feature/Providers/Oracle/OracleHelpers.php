<?php

namespace Tests\Feature\Providers\Oracle;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

trait OracleHelpers
{
    /**
     * Configure the Oracle provider with a freshly generated signing key so requests can be signed.
     */
    protected function configureOracle(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        openssl_pkey_export($key, $pem);

        config([
            'ai.providers.oracle.tenancy_id' => 'ocid1.tenancy.oc1..test',
            'ai.providers.oracle.user_id' => 'ocid1.user.oc1..test',
            'ai.providers.oracle.fingerprint' => 'aa:bb:cc:dd',
            'ai.providers.oracle.private_key' => $pem,
            'ai.providers.oracle.private_key_path' => null,
            'ai.providers.oracle.compartment_id' => 'ocid1.compartment.oc1..test',
            'ai.providers.oracle.region' => 'us-chicago-1',
            'ai.providers.oracle.url' => null,
        ]);
    }

    protected function fakeCohereTextResponse(string $text = 'Hello', string $model = 'cohere.command-a-03-2025'): PromiseInterface
    {
        return Http::response([
            'modelId' => $model,
            'modelVersion' => '1.0',
            'chatResponse' => [
                'apiFormat' => 'COHERE',
                'text' => $text,
                'finishReason' => 'COMPLETE',
                'chatHistory' => [],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 5, 'totalTokens' => 15],
            ],
        ]);
    }

    protected function fakeGenericTextResponse(string $text = 'Hello', string $model = 'meta.llama-3.3-70b-instruct'): PromiseInterface
    {
        return Http::response([
            'modelId' => $model,
            'modelVersion' => '1.0',
            'chatResponse' => [
                'apiFormat' => 'GENERIC',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'ASSISTANT',
                        'content' => [['type' => 'TEXT', 'text' => $text]],
                    ],
                    'finishReason' => 'stop',
                ]],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 5, 'totalTokens' => 15],
            ],
        ]);
    }

    protected function fakeCohereToolCallResponse(string $toolName = 'FixedNumberGenerator', array $parameters = []): PromiseInterface
    {
        return Http::response([
            'modelId' => 'cohere.command-a-03-2025',
            'chatResponse' => [
                'apiFormat' => 'COHERE',
                'text' => '',
                'finishReason' => 'COMPLETE',
                'toolCalls' => [['name' => $toolName, 'parameters' => (object) $parameters]],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 5],
            ],
        ]);
    }

    protected function fakeGenericToolCallResponse(string $toolName = 'FixedNumberGenerator', array $arguments = []): PromiseInterface
    {
        return Http::response([
            'modelId' => 'meta.llama-3.3-70b-instruct',
            'chatResponse' => [
                'apiFormat' => 'GENERIC',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'ASSISTANT',
                        'content' => [],
                        'toolCalls' => [[
                            'id' => 'call_1',
                            'type' => 'FUNCTION',
                            'name' => $toolName,
                            'arguments' => json_encode($arguments ?: (object) []),
                        ]],
                    ],
                    'finishReason' => 'tool_calls',
                ]],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 5],
            ],
        ]);
    }

    protected function fakeEmbeddingsResponse(array $embeddings = [[0.1, 0.2, 0.3]]): PromiseInterface
    {
        return Http::response([
            'embeddings' => $embeddings,
            'modelId' => 'cohere.embed-multilingual-v3.0',
            'modelVersion' => '3.0',
            'usage' => ['promptTokens' => 4, 'totalTokens' => 4],
        ]);
    }

    /**
     * Collect the events emitted by streaming an agent against the Oracle provider.
     */
    protected function collectStreamEvents(?object $agent = null, string $model = 'cohere.command-a-03-2025'): array
    {
        $agent ??= new AssistantAgent;

        $events = [];

        foreach ($agent->stream('Hello', provider: 'oracle', model: $model) as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Build an SSE response body from a list of event payloads.
     */
    protected function ssePayload(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = 'data: '.json_encode($event);
        }

        return implode("\n\n", $lines)."\n\n";
    }
}
