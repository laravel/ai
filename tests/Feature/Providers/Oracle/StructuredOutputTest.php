<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\StructuredAgent;

beforeEach(fn () => $this->configureOracle());

test('cohere structured output is parsed from the forced structured tool call', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => Http::response([
            'modelId' => 'cohere.command-a-03-2025',
            'chatResponse' => [
                'apiFormat' => 'COHERE',
                'text' => '',
                'finishReason' => 'COMPLETE',
                'toolCalls' => [['name' => 'structured_output', 'parameters' => ['symbol' => 'He']]],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 5],
            ],
        ]),
    ]);

    $response = (new StructuredAgent)->prompt(
        'What is the symbol for helium?',
        provider: 'oracle',
        model: 'cohere.command-a-03-2025',
    );

    expect($response->structured)->toEqual(['symbol' => 'He']);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['chatRequest']['isForceSingleStep'] === true
            && $body['chatRequest']['tools'][0]['name'] === 'structured_output';
    });
});

test('generic structured output forces the structured tool choice', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => Http::response([
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
                            'name' => 'structured_output',
                            'arguments' => '{"symbol":"He"}',
                        ]],
                    ],
                    'finishReason' => 'tool_calls',
                ]],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 5],
            ],
        ]),
    ]);

    $response = (new StructuredAgent)->prompt(
        'What is the symbol for helium?',
        provider: 'oracle',
        model: 'meta.llama-3.3-70b-instruct',
    );

    expect($response->structured)->toEqual(['symbol' => 'He']);

    Http::assertSent(fn ($request) => $request->data()['chatRequest']['toolChoice'] === [
        'type' => 'FUNCTION',
        'function' => ['name' => 'structured_output'],
    ]);
});
