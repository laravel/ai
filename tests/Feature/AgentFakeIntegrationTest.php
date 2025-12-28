<?php

namespace Tests\Feature;

use Tests\Feature\Agents\AssistantAgent;
use Tests\Feature\Agents\SecondaryAssistantAgent;
use Tests\TestCase;

class AgentFakeIntegrationTest extends TestCase
{
    protected $provider = 'groq';

    protected $model = 'openai/gpt-oss-20b';

    public function test_faking_one_agent_doesnt_affect_another_agent(): void
    {
        AssistantAgent::fake(fn () => 'Fake response');

        $fakeResponse = (new AssistantAgent)->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $this->provider,
            model: $this->model,
        );

        $realResponse = (new SecondaryAssistantAgent)->prompt(
            'What is the name of the PHP framework created by Taylor Otwell?',
            provider: $this->provider,
            model: $this->model,
        );

        $this->assertEquals('Fake response', $fakeResponse->text);
        $this->assertTrue(str_contains($realResponse->text, 'Laravel'));
    }
}
