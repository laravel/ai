<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;
use Tests\Fixtures\Agents\OracleAgent;

beforeEach(fn () => $this->configureOracle());

test('cohere chat requests target the chat action with the COHERE api format', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => $this->fakeCohereTextResponse('Laravel is great'),
    ]);

    $response = (new AssistantAgent)->prompt('What is Laravel?', provider: 'oracle', model: 'cohere.command-a-03-2025');

    expect((string) $response)->toBe('Laravel is great');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '20231130/actions/chat')
            && $body['servingMode']['modelId'] === 'cohere.command-a-03-2025'
            && $body['servingMode']['servingType'] === 'ON_DEMAND'
            && $body['compartmentId'] === 'ocid1.compartment.oc1..test'
            && $body['chatRequest']['apiFormat'] === 'COHERE'
            && $body['chatRequest']['message'] === 'What is Laravel?'
            && $body['chatRequest']['preambleOverride'] === 'You are a helpful assistant that responds extremely concisely to all queries.';
    });
});

test('generic chat requests use the GENERIC api format and message content parts', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => $this->fakeGenericTextResponse('Llama says hi'),
    ]);

    $response = (new AssistantAgent)->prompt('Hello there', provider: 'oracle', model: 'meta.llama-3.3-70b-instruct');

    expect((string) $response)->toBe('Llama says hi');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['chatRequest']['apiFormat'] === 'GENERIC'
            && $body['servingMode']['modelId'] === 'meta.llama-3.3-70b-instruct'
            && $body['chatRequest']['messages'][0]['role'] === 'SYSTEM'
            && $body['chatRequest']['messages'][1]['role'] === 'USER'
            && $body['chatRequest']['messages'][1]['content'][0]['text'] === 'Hello there';
    });
});

test('requests are signed with the OCI HTTP signature scheme', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => $this->fakeCohereTextResponse(),
    ]);

    (new OracleAgent)->prompt('Hi');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization')
            && str_contains($request->header('Authorization')[0], 'Signature version="1"')
            && str_contains($request->header('Authorization')[0], 'algorithm="rsa-sha256"')
            && $request->hasHeader('x-content-sha256')
            && $request->hasHeader('date');
    });
});

test('the model attribute on the agent selects the model', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => $this->fakeCohereTextResponse(),
    ]);

    (new OracleAgent)->prompt('Hi');

    Http::assertSent(fn ($request) => $request->data()['servingMode']['modelId'] === 'cohere.command-a-03-2025');
});

test('token usage is mapped from the OCI usage block', function () {
    Http::fake([
        'inference.generativeai.us-chicago-1.oci.oraclecloud.com/*' => $this->fakeCohereTextResponse(),
    ]);

    $response = (new AssistantAgent)->prompt('Hi', provider: 'oracle', model: 'cohere.command-a-03-2025');

    expect($response->usage->promptTokens)->toBe(10)
        ->and($response->usage->completionTokens)->toBe(5);
});
