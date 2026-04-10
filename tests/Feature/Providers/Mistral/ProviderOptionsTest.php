<?php

namespace Tests\Feature\Providers\Mistral;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ProviderOptionsAgent;
use Tests\Feature\Agents\ProviderOptionsWithToolsAgent;

use function Laravel\Ai\agent;

class ProviderOptionsTest extends MistralTestCase
{
    public function test_provider_options_are_included_in_mistral_request_body(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        (new ProviderOptionsAgent)->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return data_get($body, 'frequency_penalty') === 0.5
                && data_get($body, 'presence_penalty') === 0.3;
        });
    }

    public function test_request_body_does_not_contain_provider_options_when_agent_does_not_implement_interface(): void
    {
        Http::fake(['*' => $this->fakeTextResponse('Hello')]);

        agent()->prompt('Hello', provider: 'mistral');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('frequency_penalty', $body)
                && ! array_key_exists('presence_penalty', $body);
        });
    }

    public function test_provider_options_are_persisted_in_tool_call_follow_up_requests(): void
    {
        Http::fake([
            '*' => Http::sequence([
                $this->fakeToolCallResponse(),
                $this->fakeTextResponse('The number is 72019'),
            ]),
        ]);

        (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'mistral');

        $requests = Http::recorded(fn (Request $r) => true);

        $this->assertGreaterThanOrEqual(2, count($requests));

        $followUpBody = json_decode($requests[1][0]->body(), true);

        $this->assertSame(0.5, data_get($followUpBody, 'frequency_penalty'));
    }
}
