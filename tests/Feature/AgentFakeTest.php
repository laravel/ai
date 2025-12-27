<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Laravel\Ai\Data\Meta;
use Laravel\Ai\Data\Usage;
use Laravel\Ai\Responses\AgentResponse;
use Tests\Feature\Agents\AssistantAgent;
use Tests\TestCase;

class AgentFakeTest extends TestCase
{
    public function test_agents_can_be_faked(): void
    {
        AssistantAgent::fake([
            new AgentResponse((string) Str::uuid7(), 'Fake text', new Usage, new Meta),
        ]);

        $response = (new AssistantAgent)->prompt('Test prompt');

        $this->assertEquals('Fake text', $response->text);
    }
}
