<?php

namespace Tests\Feature\Providers\Xai;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agents\ProviderOptionsAgent;
use Tests\Feature\Agents\ProviderOptionsWithToolsAgent;
use Tests\TestCase;

use function Laravel\Ai\agent;

class ProviderOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.xai' => [
            ...config('ai.providers.xai'),
            'key' => 'test-key',
        ]]);
    }

    public function test_provider_options_are_included_in_xai_request_body(): void
    {
        Http::fake([
            '*' => $this->fakeXaiResponse('Hello'),
        ]);

        (new ProviderOptionsAgent)->prompt('Hello', provider: 'xai');

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return data_get($body, 'frequency_penalty') === 0.5
                && data_get($body, 'presence_penalty') === 0.3;
        });
    }

    public function test_request_body_does_not_contain_provider_options_when_agent_does_not_implement_interface(): void
    {
        Http::fake([
            '*' => $this->fakeXaiResponse('Hello'),
        ]);

        agent()->prompt('Hello', provider: 'xai');

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
                $this->fakeXaiToolCallResponse(),
                $this->fakeXaiResponse('The number is 72019'),
            ]),
        ]);

        (new ProviderOptionsWithToolsAgent)->prompt('Give me a number', provider: 'xai');

        $requests = Http::recorded(fn (Request $r) => true);

        $this->assertGreaterThanOrEqual(2, count($requests));

        $followUpBody = json_decode($requests[1][0]->body(), true);

        $this->assertSame(0.5, data_get($followUpBody, 'frequency_penalty'));
    }

    protected function fakeXaiToolCallResponse(): PromiseInterface
    {
        return Http::response([
            'id' => 'resp_tool_123',
            'object' => 'response',
            'status' => 'completed',
            'model' => 'grok-4-1-fast-reasoning',
            'output' => [
                [
                    'type' => 'function_call',
                    'id' => 'fc_123',
                    'call_id' => 'call_123',
                    'name' => 'FixedNumberGenerator',
                    'arguments' => '{}',
                    'status' => 'completed',
                ],
            ],
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ]);
    }

    protected function fakeXaiResponse(string $text): PromiseInterface
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
                        ['type' => 'output_text', 'text' => $text],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 1,
                'output_tokens' => 1,
            ],
        ]);
    }
}
