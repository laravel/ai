<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Laravel\Ai\Data\Meta;
use Laravel\Ai\Data\Usage;
use Laravel\Ai\Responses\AgentResponse;
use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\StructuredAgent;
use Tests\TestCase;

class AgentFakeTest extends TestCase
{
    public function test_agents_can_be_faked(): void
    {
        AssistantAgent::fake([
            'First response',
            new AgentResponse((string) Str::uuid7(), 'Second response', new Usage, new Meta),
        ]);

        $response = (new AssistantAgent)->prompt('Test prompt');
        $this->assertEquals('First response', $response->text);

        $response = (new AssistantAgent)->prompt('Test prompt');
        $this->assertEquals('Second response', $response->text);
    }

    public function test_agents_with_structured_output_can_be_faked(): void
    {
        StructuredAgent::fake([
            ['symbol' => 'Au'],
            ['symbol' => 'Pb'],
        ]);

        $response = (new StructuredAgent)->prompt('Test prompt');
        $this->assertEquals('Au', $response['symbol']);

        $response = (new StructuredAgent)->prompt('Test prompt');
        $this->assertEquals('Pb', $response['symbol']);
    }

    public function test_agent_streams_can_be_faked(): void
    {
        AssistantAgent::fake([
            'First response',
            new AgentResponse((string) Str::uuid7(), 'Second response', new Usage, new Meta),
        ]);

        $response = (new AssistantAgent)->stream('Test prompt');
        $response->each(fn () => true);
        $this->assertEquals('First response', $response->text);
        $this->assertCount(6, $response->events);

        $response = (new AssistantAgent)->stream('Test prompt');
        $response->each(fn () => true);
        $this->assertEquals('Second response', $response->text);
        $this->assertCount(6, $response->events);
    }
}
