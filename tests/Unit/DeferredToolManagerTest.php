<?php

namespace Tests\Unit;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\DeferredToolManager;
use Laravel\Ai\Events\DeferredToolQueued;
use Laravel\Ai\Jobs\RunDeferredTool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Tests\TestCase;

class DeferredToolManagerTest extends TestCase
{
    public function test_defer_dispatches_deferred_job_and_returns_pending_payload(): void
    {
        Bus::fake();
        Event::fake();

        $manager = $this->app->make(DeferredToolManager::class);
        $tool = new DeferredToolManagerTestTool;

        $result = $manager->defer($tool, ['query' => 'test']);

        $this->assertSame('pending', $result['status']);
        $this->assertArrayHasKey('tool_call_id', $result);

        Bus::assertDispatched(RunDeferredTool::class, function (RunDeferredTool $job) use ($result) {
            return $job->toolClass === DeferredToolManagerTestTool::class
                && $job->arguments === ['query' => 'test']
                && $job->toolCallId === $result['tool_call_id'];
        });

        Event::assertDispatched(DeferredToolQueued::class, function (DeferredToolQueued $event) use ($result) {
            return $event->toolClass === DeferredToolManagerTestTool::class
                && $event->arguments === ['query' => 'test']
                && $event->toolCallId === $result['tool_call_id'];
        });
    }

    public function test_resume_invokes_tool_handle(): void
    {
        $manager = $this->app->make(DeferredToolManager::class);

        $tool = new DeferredToolManagerRecordingTool;

        $result = $manager->resume($tool, ['id' => 1], 'call_123');

        $this->assertSame('done', (string) $result);
        $this->assertSame(['id' => 1], $tool->arguments);
    }

    public function test_resume_resolves_tool_from_class_name(): void
    {
        $manager = $this->app->make(DeferredToolManager::class);

        $result = $manager->resume(DeferredToolManagerTestTool::class, ['id' => 1], 'call_123');

        $this->assertSame('ok', (string) $result);
    }
}

class DeferredToolManagerTestTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'A deferred test tool';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'ok';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class DeferredToolManagerRecordingTool implements Tool
{
    public array $arguments = [];

    public function description(): Stringable|string
    {
        return 'A deferred test tool';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->arguments = $request->all();

        return 'done';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
