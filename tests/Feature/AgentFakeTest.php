<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Laravel\Ai\AgentPrompt;
use Laravel\Ai\Data\Meta;
use Laravel\Ai\Data\Usage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\TestCase;

class AgentFakeTest extends TestCase
{
    public function test_agents_can_be_faked(): void
    {
        AssistantAgent::fake([
            'First response',
            fn (AgentPrompt $prompt) => 'Second response ('.$prompt->prompt.')',
            new AgentResponse((string) Str::uuid7(), 'Third response', new Usage, new Meta),
        ]);

        $response = (new AssistantAgent)->prompt('First prompt');
        $this->assertEquals('First response', $response->text);

        $response = (new AssistantAgent)->prompt('Second prompt');
        $this->assertEquals('Second response (Second prompt)', $response->text);

        $response = (new AssistantAgent)->prompt('Third prompt');
        $this->assertEquals('Third response', $response->text);
    }

    public function test_agents_can_be_faked_with_no_predefined_responses(): void
    {
        AssistantAgent::fake();

        $response = (new AssistantAgent)->prompt('First prompt');
        $this->assertEquals('Fake response for prompt: First prompt', $response->text);

        $response = (new AssistantAgent)->prompt('Second prompt');
        $this->assertEquals('Fake response for prompt: Second prompt', $response->text);
    }

    public function test_agents_can_be_faked_with_a_single_closure(): void
    {
        AssistantAgent::fake(function (AgentPrompt $prompt) {
            return 'Fake response for prompt: '.$prompt->prompt;
        });

        $response = (new AssistantAgent)->prompt('First prompt');
        $this->assertEquals('Fake response for prompt: First prompt', $response->text);

        $response = (new AssistantAgent)->prompt('Second prompt');
        $this->assertEquals('Fake response for prompt: Second prompt', $response->text);
    }

    public function test_agents_can_prevent_stray_prompts(): void
    {
        $this->expectException(RuntimeException::class);

        AssistantAgent::fake()->preventStrayPrompts();

        $response = (new AssistantAgent)->prompt('First prompt');
    }

    public function test_agents_with_structured_output_can_be_faked(): void
    {
        StructuredAgent::fake([
            ['symbol' => 'Au'],
            fn (AgentPrompt $prompt) => ['symbol' => 'Ag ('.$prompt->prompt.')'],
            new StructuredAgentResponse(
                (string) Str::uuid7(),
                ['symbol' => 'Pb'],
                json_encode(['symbol' => 'Pb']),
                new Usage,
                new Meta,
            ),
        ]);

        $response = (new StructuredAgent)->prompt('Gold prompt');
        $this->assertEquals('Au', $response['symbol']);

        $response = (new StructuredAgent)->prompt('Silver prompt');
        $this->assertEquals('Ag (Silver prompt)', $response['symbol']);

        $response = (new StructuredAgent)->prompt('Lead prompt');
        $this->assertEquals('Pb', $response['symbol']);
    }

    public function test_agent_streams_can_be_faked(): void
    {
        AssistantAgent::fake([
            'First response',
            fn (AgentPrompt $prompt) => 'Second response ('.$prompt->prompt.')',
            new AgentResponse((string) Str::uuid7(), 'Third response', new Usage, new Meta),
        ]);

        $response = (new AssistantAgent)->stream('First prompt');
        $response->each(fn () => true);
        $this->assertEquals('First response', $response->text);
        $this->assertCount(6, $response->events);

        $response = (new AssistantAgent)->stream('Second prompt');
        $response->each(fn () => true);
        $this->assertEquals('Second response (Second prompt)', $response->text);
        $this->assertCount(8, $response->events);

        $response = (new AssistantAgent)->stream('Third prompt');
        $response->each(fn () => true);
        $this->assertEquals('Third response', $response->text);
        $this->assertCount(6, $response->events);
    }
}
