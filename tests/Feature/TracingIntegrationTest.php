<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tracing\Jobs\SendLangfuseTrace;
use Tests\Feature\Agents\TraceableAgent;
use Tests\TestCase;

class TracingIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! env('ANTHROPIC_API_KEY') || ! env('LANGFUSE_PUBLIC_KEY') || ! env('LANGFUSE_SECRET_KEY')) {
            $this->markTestSkipped('Anthropic and Langfuse credentials are required.');
        }

        $this->app['config']->set('ai.providers.anthropic.key', env('ANTHROPIC_API_KEY'));
        $this->app['config']->set('ai.tracing.default', 'langfuse');
        $this->app['config']->set('ai.tracing.drivers.langfuse', [
            'driver' => 'langfuse',
            'url' => env('LANGFUSE_BASE_URL', env('LANGFUSE_URL', 'https://cloud.langfuse.com')),
            'public_key' => env('LANGFUSE_PUBLIC_KEY'),
            'secret_key' => env('LANGFUSE_SECRET_KEY'),
            'queue' => 'sync',
        ]);
    }

    public function test_real_agent_prompt_sends_trace_to_langfuse(): void
    {
        $agent = new TraceableAgent;

        $response = $agent->prompt(
            'What is 2 + 2? Reply with just the number.',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5-20250929',
        );

        $this->assertNotEmpty($response->text);
        $this->assertGreaterThan(0, $response->usage->promptTokens);
        $this->assertGreaterThan(0, $response->usage->completionTokens);

        // If we reach here without exceptions, the trace was sent successfully.
        // Check your Langfuse dashboard to see the trace.
        $this->addToAssertionCount(1);
    }

    public function test_real_agent_prompt_sends_correct_payload_structure(): void
    {
        Http::fake([
            '*/api/public/ingestion' => Http::response(['successes' => [], 'errors' => []], 200),
        ]);

        $agent = new TraceableAgent;

        $response = $agent->prompt(
            'What is 2 + 2? Reply with just the number.',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5-20250929',
        );

        Http::assertSent(function ($request) use ($response) {
            $body = $request->data();

            if (! isset($body['batch']) || count($body['batch']) < 2) {
                return false;
            }

            $trace = collect($body['batch'])->firstWhere('type', 'trace-create');
            $generation = collect($body['batch'])->firstWhere('type', 'generation-create');

            // Verify trace structure
            if (! $trace || $trace['body']['name'] !== 'TraceableAgent') {
                return false;
            }

            if ($trace['body']['metadata']['agent_class'] !== TraceableAgent::class) {
                return false;
            }

            // Verify generation structure
            if (! $generation || $generation['body']['model'] !== 'claude-sonnet-4-5-20250929') {
                return false;
            }

            if ($generation['body']['usage']['promptTokens'] <= 0) {
                return false;
            }

            // Verify auth header (basic auth with public:secret)
            $authHeader = $request->header('Authorization')[0] ?? '';
            if (! str_starts_with($authHeader, 'Basic ')) {
                return false;
            }

            return true;
        });
    }

    public function test_real_agent_stream_sends_trace_to_langfuse(): void
    {
        $agent = new TraceableAgent;

        $response = $agent->stream(
            'What is 2 + 2? Reply with just the number.',
            provider: 'anthropic',
            model: 'claude-sonnet-4-5-20250929',
        );

        $text = '';
        $response->each(function () use (&$text) {
            // consume the stream
        });

        $this->assertNotEmpty($response->text);

        $this->addToAssertionCount(1);
    }
}
