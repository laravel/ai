<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\AssistantAgent;

beforeEach(fn () => $this->configureOracle());

test('oracle requests build the regional inference endpoint from the configured region', function () {
    config(['ai.providers.oracle.region' => 'eu-frankfurt-1', 'ai.providers.oracle.url' => null]);

    Http::fake([
        'inference.generativeai.eu-frankfurt-1.oci.oraclecloud.com/*' => $this->fakeCohereTextResponse(),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'oracle', model: 'cohere.command-a-03-2025');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'inference.generativeai.eu-frankfurt-1.oci.oraclecloud.com'));
});

test('oracle requests use an explicit url override when configured', function () {
    config(['ai.providers.oracle.url' => 'https://custom-proxy.example.com']);

    Http::fake([
        'custom-proxy.example.com/*' => $this->fakeCohereTextResponse(),
    ]);

    (new AssistantAgent)->prompt('Hi', provider: 'oracle', model: 'cohere.command-a-03-2025');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'https://custom-proxy.example.com'));
});
