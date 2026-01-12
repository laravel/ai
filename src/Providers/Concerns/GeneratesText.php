<?php

namespace Laravel\Ai\Providers\Concerns;

use Closure;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use function Laravel\Ai\pipeline;

trait GeneratesText
{
    /**
     * Invoke the given agent.
     */
    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        $invocationId = (string) Str::uuid7();

        $processedPrompt = null;

        $response = pipeline()
            ->send($prompt)
            ->through($this->gatherMiddlewareFor($prompt->agent))
            ->then(function (AgentPrompt $prompt) use ($invocationId, &$processedPrompt) {
                $processedPrompt = $prompt;

                $this->events->dispatch(new PromptingAgent($invocationId, $prompt));

                $agent = $prompt->agent;

                $messages = $agent instanceof Conversational ? $agent->messages() : [];

                $messages[] = new UserMessage($prompt->prompt, $prompt->attachments->all());

                $this->listenForToolInvocations($invocationId, $agent);

                $response = $this->textGateway()->generateText(
                    $this,
                    $prompt->model,
                    (string) $agent->instructions(),
                    $messages,
                    $agent instanceof HasTools ? $agent->tools() : [],
                    $agent instanceof HasStructuredOutput ? $agent->schema(new JsonSchemaTypeFactory) : null,
                    TextGenerationOptions::forAgent($agent),
                );

                return $agent instanceof HasStructuredOutput
                    ? (new StructuredAgentResponse($invocationId, $response->structured, $response->text, $response->usage, $response->meta))
                        ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                        ->withSteps($response->steps)
                    : (new AgentResponse($invocationId, $response->text, $response->usage, $response->meta))
                        ->withMessages($response->messages)
                        ->withSteps($response->steps);
            });

        $this->events->dispatch(
            new AgentPrompted($invocationId, $processedPrompt, $response)
        );

        return $response;
    }

    /**
     * Gather the middleware for the given agent.
     */
    protected function gatherMiddlewareFor(Agent $agent): array
    {
        $middleware = Ai::hasFakeGatewayFor(get_class($agent)) ? [function (AgentPrompt $prompt, Closure $next) {
            Ai::recordPrompt($prompt);

            return $next($prompt);
        }] : [];

        if (in_array(RemembersConversations::class, class_uses_recursive($agent))
            && $agent->hasConversationParticipant()) {
            $middleware[] = resolve(RememberConversation::class);
        }

        return $agent instanceof HasMiddleware
            ? [...$middleware, ...$agent->middleware()]
            : $middleware;
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
