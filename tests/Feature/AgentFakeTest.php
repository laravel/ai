<?php

namespace Tests\Feature;

use Exception;
use Laravel\Ai\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
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
            fn (string $prompt) => 'Second response ('.$prompt.')',
            new TextResponse('Third response', new Usage, new Meta),
        ]);

        $response = (new AssistantAgent)->prompt('First prompt');
        $this->assertEquals('First response', $response->text);

        $response = (new AssistantAgent)->prompt('Second prompt');
        $this->assertEquals('Second response (Second prompt)', $response->text);

        $response = (new AssistantAgent)->prompt('Third prompt');
        $this->assertEquals('Third response', $response->text);

        AssistantAgent::assertPrompted('First prompt');
        AssistantAgent::assertPromptedTimes('First prompt', 1);
        AssistantAgent::assertWasntPrompted('Missing prompt');

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->prompt === 'First prompt';
        });
    }

    public function test_agents_can_be_faked_with_no_predefined_responses(): void
    {
        AssistantAgent::fake();

        $response = (new AssistantAgent)->prompt('First prompt');
        $this->assertEquals('Fake response for prompt: First prompt', $response->text);

        $response = (new AssistantAgent)->prompt('Second prompt');
        $this->assertEquals('Fake response for prompt: Second prompt', $response->text);
    }

    public function test_agents_can_be_faked_with_a_single_closure_that_is_invoked_for_every_prompt(): void
    {
        AssistantAgent::fake(function (string $prompt) {
            return 'Fake response for prompt: '.$prompt;
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
            fn (string $prompt) => ['symbol' => 'Ag ('.$prompt.')'],
            new StructuredTextResponse(
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
            fn (string $prompt) => 'Second response ('.$prompt.')',
            new TextResponse('Third response', new Usage, new Meta),
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

    public function test_fake_closures_can_throw_exceptions()
    {
        $this->expectException(Exception::class);

        AssistantAgent::fake(function () {
            throw new Exception('Something went wrong');
        });

        $response = (new AssistantAgent)->prompt('Test prompt');
    }
}
