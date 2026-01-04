<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

trait GeneratesText
{
    /**
     * Invoke the given agent.
     */
    public function prompt(Agent $agent, string $prompt, array $attachments, string $model): AgentResponse
    {
        $invocationId = (string) Str::uuid7();

        $this->events->dispatch(new PromptingAgent($invocationId, $agentPrompt = new AgentPrompt(
            $agent, $prompt, $attachments, $this, $model
        )));

        $messages = $agent instanceof Conversational ? $agent->messages() : [];

        $messages[] = new UserMessage($prompt, $attachments);

        $this->listenForToolInvocations($invocationId, $agent);

        $response = $this->textGateway()->generateText(
            $this,
            $model,
            (string) $agent->instructions(),
            $messages,
            $agent instanceof HasTools ? $agent->tools() : [],
            $agent instanceof HasStructuredOutput ? $agent->schema(new JsonSchemaTypeFactory) : null,
            TextGenerationOptions::forAgent($agent),
        );

        $response = $agent instanceof HasStructuredOutput
            ? (new StructuredAgentResponse($invocationId, $response->structured, $response->text, $response->usage, $response->meta))
                ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                ->withSteps($response->steps)
            : (new AgentResponse($invocationId, $response->text, $response->usage, $response->meta))
                ->withMessages($response->messages)
                ->withSteps($response->steps);

        $this->events->dispatch(
            new AgentPrompted($invocationId, $agentPrompt, $response)
        );

        return $response;
    }

    /**
     * Listen for gateway tool invocations and dispatch events for the given agent when the tools are invoked.
     */
    protected function listenForToolInvocations(string $invocationId, Agent $agent): void
    {
        $this->textGateway()->onToolInvocation(
            invoking: function (Tool $tool, array $arguments) use ($invocationId, $agent) {
                $this->currentToolInvocationId = (string) Str::uuid7();

                $this->events->dispatch(new InvokingTool(
                    $invocationId, $this->currentToolInvocationId, $agent, $tool, $arguments
                ));
            },
            invoked: function (Tool $tool, array $arguments, mixed $result) use ($invocationId, $agent) {
                $this->events->dispatch(new ToolInvoked(
                    $invocationId, $this->currentToolInvocationId, $agent, $tool, $arguments, $result
                ));
            },
        );
    }
}
