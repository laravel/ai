<?php

namespace Tests\Feature\Providers\Mistral;

use Tests\Feature\Agents\MistralAgent;

class AgentFakeTest extends MistralTestCase
{
    public function test_mistral_agent_can_be_faked(): void
    {
        MistralAgent::fake(['Test response']);

        $response = (new MistralAgent)->prompt('Hello');

        $this->assertEquals('Test response', $response->text);
    }

    public function test_mistral_agent_fake_with_closure(): void
    {
        MistralAgent::fake(fn (string $prompt) => "Echo: {$prompt}");

        $response = (new MistralAgent)->prompt('Hello world');

        $this->assertEquals('Echo: Hello world', $response->text);
    }

    public function test_mistral_agent_fake_with_no_predefined_responses(): void
    {
        MistralAgent::fake();

        $response = (new MistralAgent)->prompt('Hello');

        $this->assertEquals('Fake response for prompt: Hello', $response->text);
    }

    public function test_mistral_agent_fake_records_prompts(): void
    {
        MistralAgent::fake();

        (new MistralAgent)->prompt('Hello');

        MistralAgent::assertPrompted('Hello');
        MistralAgent::assertNotPrompted('Goodbye');
    }

    public function test_mistral_agent_stream_can_be_faked(): void
    {
        MistralAgent::fake(['Streamed response']);

        $response = (new MistralAgent)->stream('Hello');
        $response->each(fn () => true);

        $this->assertEquals('Streamed response', $response->text);
    }
}
