<?php

namespace Tests\Feature;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\ConversationalAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\Feature\Agents\ToolUsingAgent;
use Tests\TestCase;

use function Laravel\Ai\agent;

class AgentIntegrationTest extends TestCase
{
    protected $provider = 'groq';

    protected $model = 'openai/gpt-oss-20b';

    protected $toolProvider = 'anthropic';

    protected $toolModel = 'claude-haiku-4-5-20251001';

    public function test_agents_can_get_a_simple_text_response(): void
    {
        Event::fake();

        $agent = new AssistantAgent;

        $response = $agent->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $this->provider,
            model: $this->model,
        );

        $this->assertTrue(str_contains($response->text, 'Laravel'));
        $this->assertTrue(Str::isUuid($response->invocationId, 7));
        $this->assertTrue($response->messages->count() > 0);
        $this->assertEquals($response->meta->provider, 'groq');
        $this->assertEquals($response->meta->model, 'openai/gpt-oss-20b');
        $this->assertTrue($response->steps->count() > 0);

        Event::assertDispatched(PromptingAgent::class);
        Event::assertDispatched(AgentPrompted::class);
    }

    public function test_ad_hoc_agents_can_be_prompted(): void
    {
        $response = agent()->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $this->provider,
            model: $this->model,
        );

        $this->assertTrue(str_contains($response->text, 'Laravel'));
    }

    public function test_agents_can_stream_a_response(): void
    {
        Event::fake();

        $agent = new AssistantAgent;

        $response = $agent->stream(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $this->provider,
            model: $this->model,
        )->then(function (StreamedAgentResponse $response) {
            $_SERVER['__testing.response'] = $response;
        })->then(function () {
            $_SERVER['__testing.invoked'] = true;
        });

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        $this->assertTrue(
            collect($events)
                ->whereInstanceOf(TextDelta::class)
                ->isNotEmpty()
        );

        $this->assertTrue(str_contains($response->text, 'Laravel'));
        $this->assertCount(count($events), $_SERVER['__testing.response']->events);
        $this->assertTrue($_SERVER['__testing.invoked']);

        Event::assertDispatched(StreamingAgent::class);
        Event::assertDispatched(AgentStreamed::class);

        unset($_SERVER['__testing.response']);
        unset($_SERVER['__testing.invoked']);
    }

    public function test_agents_can_queue_a_response(): void
    {
        $agent = new AssistantAgent;

        $agent->queue(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $this->provider,
            model: $this->model,
        )->then(function (AgentResponse $response) {
            $_ENV['__testing.response'] = $response;
        });

        $response = $_ENV['__testing.response'];

        $this->assertTrue(str_contains($response->text, 'Laravel'));

        unset($_SERVER['__testing.response']);
    }

    public function test_ad_hoc_agents_can_queue_a_response(): void
    {
        agent()->queue(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $this->provider,
            model: $this->model,
        )->then(function (AgentResponse $response) {
            $_ENV['__testing.response'] = $response;
        });

        $response = $_ENV['__testing.response'];

        $this->assertTrue(str_contains($response->text, 'Laravel'));

        unset($_SERVER['__testing.response']);
    }

    public function test_ad_hoc_structured_agents_can_queue_a_response(): void
    {
        agent(
            schema: fn ($schema) => [
                'symbol' => $schema->string()->required(),
            ]
        )->queue(
            'What is the chemical symbol for silver?',
            provider: $this->provider,
            model: $this->model,
        )->then(function (AgentResponse $response) {
            $_ENV['__testing.response'] = $response;
        });

        $response = $_ENV['__testing.response'];

        $this->assertEquals('ag', strtolower($response['symbol']));

        unset($_SERVER['__testing.response']);
    }

    public function test_agents_can_have_conversation_state(): void
    {
        $agent = new ConversationalAgent;

        $response = $agent->prompt(
            'What did I say my name was?',
            provider: $this->provider,
            model: $this->model,
        );

        $this->assertTrue(str_contains($response->text, 'Taylor'));
    }

    public function test_agents_can_have_structured_output(): void
    {
        $agent = new StructuredAgent;

        $response = $agent->prompt(
            'What is the chemical symbol for silver?',
            provider: $this->provider,
            model: $this->model,
        );

        $this->assertEquals('ag', strtolower($response['symbol']));
        $this->assertTrue($response->steps->count() > 0);
    }

    public function test_ad_hoc_agents_can_have_structured_output(): void
    {
        $response = agent(
            schema: fn (JsonSchema $schema) => [
                'symbol' => $schema->string()->required(),
            ],
        )->prompt(
            'What is the chemical symbol for silver?',
            provider: $this->provider,
            model: $this->model,
        );

        $this->assertEquals('ag', strtolower($response['symbol']));
    }

    public function test_agents_can_use_tools(): void
    {
        Event::fake();

        // Verify with a random number...
        $agent = new ToolUsingAgent;

        $response = $agent->prompt(
            'Can I have a random number between 1 and 1000?',
            provider: $this->toolProvider,
            model: $this->toolModel,
        );

        $this->assertTrue($response['number'] >= 1 && $response['number'] <= 1000);
        $this->assertTrue(count($response->toolCalls) === 1);
        $this->assertTrue(count($response->toolResults) === 1);

        Event::assertDispatched(InvokingTool::class);

        Event::assertDispatched(ToolInvoked::class, function ($event) {
            return ! is_null($event->toolInvocationId);
        });

        // Verify with a fixed response...
        $agent = new ToolUsingAgent(fixed: true);

        $response = $agent->prompt(
            'Can I have a random number?',
            provider: $this->toolProvider,
            model: $this->toolModel,
        );

        $this->assertTrue($response['number'] === 72019);
    }

    public function test_agent_tool_exception_handling_is_not_magical(): void
    {
        Event::fake();

        $agent = new ToolUsingAgent(toolThrowsException: true);

        $caught = false;

        try {
            $response = $agent->prompt(
                'Can I have a random number between 1 and 1000?',
                provider: $this->toolProvider,
                model: $this->toolModel,
            );

            $text = $response->text;
        } catch (\Exception $e) {
            $caught = true;

            $this->assertTrue($e::class === 'Exception');
            $this->assertEquals('Forced to throw exception.', $e->getMessage());
        }

        $this->assertTrue($caught);
    }
}
