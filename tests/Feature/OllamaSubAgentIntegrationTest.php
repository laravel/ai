<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\SubAgentTool;
use Tests\Feature\Agents\CalledTrackingSubAgent;
use Tests\Feature\Agents\OllamaDelegatingAgent;
use Tests\TestCase;

class OllamaSubAgentIntegrationTest extends TestCase
{
    protected string $provider = 'ollama';

    protected string $model = 'qwen3:8b';

    public function test_ollama_can_invoke_subagent_tool(): void
    {
        if (! filter_var(env('RUN_OLLAMA_INTEGRATION', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set RUN_OLLAMA_INTEGRATION=true to run Ollama integration tests.');
        }

        CalledTrackingSubAgent::$tasks = [];

        Event::fake();

        $response = (new OllamaDelegatingAgent)->prompt(
            'Delegate to called_tracking_subagent with task exactly "integration ping". Return only the delegated output.',
            provider: $this->provider,
            model: $this->model,
        );

        $this->assertNotEmpty(
            CalledTrackingSubAgent::$tasks,
            sprintf(
                "Sub-agent tool was not called. Response text: %s\nTool calls: %s\nTool results: %s",
                $response->text,
                json_encode($response->toolCalls->toArray(), JSON_PRETTY_PRINT),
                json_encode($response->toolResults->toArray(), JSON_PRETTY_PRINT),
            )
        );
        $this->assertSame('integration ping', CalledTrackingSubAgent::$tasks[0]);
        $this->assertSame('ollama', $response->meta->provider);
        $this->assertSame($this->model, $response->meta->model);

        Event::assertDispatched(InvokingTool::class, function ($event) {
            return $event->tool instanceof SubAgentTool
                && ($event->arguments['task'] ?? null) === 'integration ping';
        });

        Event::assertDispatched(ToolInvoked::class, function ($event) {
            return $event->tool instanceof SubAgentTool
                && ($event->arguments['task'] ?? null) === 'integration ping';
        });
    }
}
