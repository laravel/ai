<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Laravel\Ai\Tracing\Drivers\LangfuseDriver;
use Laravel\Ai\Tracing\Drivers\LogDriver;
use Laravel\Ai\Tracing\Drivers\NullDriver;
use Laravel\Ai\Tracing\Jobs\SendLangfuseTrace;
use Laravel\Ai\Tracing\TracingManager;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\TraceableAgent;
use Tests\TestCase;

class TracingTest extends TestCase
{
    public function test_tracing_manager_resolves_log_driver(): void
    {
        $manager = $this->app->make(TracingManager::class);

        $this->assertInstanceOf(LogDriver::class, $manager->driver('log'));
    }

    public function test_tracing_manager_resolves_langfuse_driver(): void
    {
        $manager = $this->app->make(TracingManager::class);

        $this->assertInstanceOf(LangfuseDriver::class, $manager->driver('langfuse'));
    }

    public function test_tracing_manager_resolves_default_driver(): void
    {
        $this->app['config']->set('ai.tracing.default', 'log');

        $manager = $this->app->make(TracingManager::class);

        $this->assertInstanceOf(LogDriver::class, $manager->driver());
    }

    public function test_tracing_manager_throws_for_undefined_driver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tracing driver [nonexistent] is not defined.');

        $this->app->make(TracingManager::class)->driver('nonexistent');
    }

    public function test_tracing_manager_caches_resolved_drivers(): void
    {
        $manager = $this->app->make(TracingManager::class);

        $first = $manager->driver('log');
        $second = $manager->driver('log');

        $this->assertSame($first, $second);
    }

    public function test_null_driver_can_be_configured(): void
    {
        $this->app['config']->set('ai.tracing.drivers.null', [
            'driver' => 'null',
        ]);

        $manager = $this->app->make(TracingManager::class);

        $this->assertInstanceOf(NullDriver::class, $manager->driver('null'));
    }

    public function test_agents_without_traceable_trait_are_unaffected(): void
    {
        Log::spy();

        AssistantAgent::fake(['Response']);

        (new AssistantAgent)->prompt('Test prompt');

        Log::shouldNotHaveReceived('channel');
    }

    public function test_log_driver_logs_prompt_and_completion(): void
    {
        $this->app['config']->set('ai.tracing.default', 'log');

        $log = Log::spy();

        TraceableAgent::fake(['Response']);

        (new TraceableAgent)->prompt('Test prompt');

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_log_driver_logs_to_configured_channel(): void
    {
        $this->app['config']->set('ai.tracing.default', 'log');
        $this->app['config']->set('ai.tracing.drivers.log.channel', 'stderr');

        $channelLog = Log::partialMock();
        $channelLog->shouldReceive('channel')
            ->with('stderr')
            ->andReturnSelf()
            ->atLeast()->once();
        $channelLog->shouldReceive('info')->atLeast()->once();

        TraceableAgent::fake(['Response']);

        (new TraceableAgent)->prompt('Test prompt');
    }

    public function test_log_driver_logs_streaming(): void
    {
        $this->app['config']->set('ai.tracing.default', 'log');

        Log::spy();

        TraceableAgent::fake(['Streamed response']);

        $response = (new TraceableAgent)->stream('Test prompt');
        $response->each(fn () => true);

        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    public function test_agent_can_override_tracing_driver(): void
    {
        $this->app['config']->set('ai.tracing.default', 'log');
        $this->app['config']->set('ai.tracing.drivers.null', [
            'driver' => 'null',
        ]);

        Log::spy();

        TraceableAgent::fake(['Response']);

        (new TraceableAgent)->useTracingDriver('null')->prompt('Test prompt');

        Log::shouldNotHaveReceived('info');
    }

    public function test_langfuse_driver_dispatches_queued_job_on_prompt(): void
    {
        Queue::fake();

        $this->app['config']->set('ai.tracing.default', 'langfuse');
        $this->app['config']->set('ai.tracing.drivers.langfuse', [
            'driver' => 'langfuse',
            'url' => 'https://cloud.langfuse.com',
            'public_key' => 'pk-test',
            'secret_key' => 'sk-test',
            'queue' => 'tracing',
        ]);

        TraceableAgent::fake(['Response']);

        (new TraceableAgent)->prompt('Test prompt');

        Queue::assertPushed(SendLangfuseTrace::class, function (SendLangfuseTrace $job) {
            return $job->url === 'https://cloud.langfuse.com'
                && $job->publicKey === 'pk-test'
                && $job->secretKey === 'sk-test'
                && $job->queue === 'tracing'
                && isset($job->payload['batch'])
                && count($job->payload['batch']) >= 2;
        });
    }

    public function test_langfuse_driver_dispatches_queued_job_on_stream(): void
    {
        Queue::fake();

        $this->app['config']->set('ai.tracing.default', 'langfuse');
        $this->app['config']->set('ai.tracing.drivers.langfuse', [
            'driver' => 'langfuse',
            'url' => 'https://cloud.langfuse.com',
            'public_key' => 'pk-test',
            'secret_key' => 'sk-test',
            'queue' => 'default',
        ]);

        TraceableAgent::fake(['Streamed response']);

        $response = (new TraceableAgent)->stream('Test prompt');
        $response->each(fn () => true);

        Queue::assertPushed(SendLangfuseTrace::class);
    }

    public function test_langfuse_payload_contains_trace_and_generation(): void
    {
        Queue::fake();

        $this->app['config']->set('ai.tracing.default', 'langfuse');
        $this->app['config']->set('ai.tracing.drivers.langfuse', [
            'driver' => 'langfuse',
            'url' => 'https://cloud.langfuse.com',
            'public_key' => 'pk-test',
            'secret_key' => 'sk-test',
            'queue' => 'default',
        ]);

        TraceableAgent::fake(['Response']);

        (new TraceableAgent)->prompt('Test prompt');

        Queue::assertPushed(SendLangfuseTrace::class, function (SendLangfuseTrace $job) {
            $batch = $job->payload['batch'];
            $types = array_column($batch, 'type');

            return in_array('trace-create', $types)
                && in_array('generation-create', $types);
        });
    }

    public function test_langfuse_trace_contains_agent_metadata(): void
    {
        Queue::fake();

        $this->app['config']->set('ai.tracing.default', 'langfuse');
        $this->app['config']->set('ai.tracing.drivers.langfuse', [
            'driver' => 'langfuse',
            'url' => 'https://cloud.langfuse.com',
            'public_key' => 'pk-test',
            'secret_key' => 'sk-test',
            'queue' => 'default',
        ]);

        TraceableAgent::fake(['Response']);

        (new TraceableAgent)->prompt('Test prompt');

        Queue::assertPushed(SendLangfuseTrace::class, function (SendLangfuseTrace $job) {
            $trace = collect($job->payload['batch'])->firstWhere('type', 'trace-create');

            return $trace['body']['name'] === 'TraceableAgent'
                && $trace['body']['input'] === 'Test prompt'
                && $trace['body']['output'] === 'Response'
                && $trace['body']['metadata']['agent_class'] === TraceableAgent::class;
        });
    }

    public function test_langfuse_job_is_not_dispatched_for_non_traceable_agents(): void
    {
        Queue::fake();

        $this->app['config']->set('ai.tracing.default', 'langfuse');

        AssistantAgent::fake(['Response']);

        (new AssistantAgent)->prompt('Test prompt');

        Queue::assertNotPushed(SendLangfuseTrace::class);
    }
}
