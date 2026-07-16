<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Ai;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Conversations\RememberConversation;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\PendingStep;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Middleware\Middleware;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Tools\ToolResolver;

trait GeneratesText
{
    protected string $currentToolInvocationId;

    /**
     * Invoke the given agent.
     */
    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        $invocationId = (string) Str::uuid7();

        $agent = $prompt->agent;

        if (Ai::hasFakeGatewayFor($agent::class)) {
            Ai::recordPrompt($prompt);
        }

        $middleware = $this->gatherMiddlewareFor($agent);

        $recorder = $this->conversationRecorderFor($agent);

        $this->events->dispatch(new PromptingAgent($invocationId, $prompt));

        $messages = [
            ...($agent instanceof Conversational ? $agent->messages() : []),
            new UserMessage($prompt->prompt, $prompt->attachments->all()),
        ];

        $this->listenForToolInvocations($invocationId, $agent);

        $schema = $agent instanceof HasStructuredOutput ? $agent->schema(new JsonSchemaTypeFactory) : null;

        $result = $this->textGenerationLoop()->generate(
            $this,
            $prompt->model,
            (string) $agent->instructions(),
            $messages,
            $this->resolveTools($agent),
            $schema,
            TextGenerationOptions::forAgent($agent)->withMiddleware($middleware),
            $prompt->timeout,
        );

        if ($result instanceof StructuredTextResponse) {
            $response = new StructuredAgentResponse($invocationId, $result->structured, $result->text, $result->usage, $result->meta);

            $response->withToolCallsAndResults($result->toolCalls, $result->toolResults)
                ->withSteps($result->steps);
        } else {
            $response = new AgentResponse($invocationId, $result->text, $result->usage, $result->meta);

            $response->withMessages($result->messages)
                ->withToolCallsAndResults($result->toolCalls, $result->toolResults)
                ->withSteps($result->steps);
        }

        if ($recorder !== null) {
            $response->then(fn (AgentResponse $response) => $recorder($prompt, $response));
        }

        $this->events->dispatch(
            new AgentPrompted($invocationId, $prompt, $response)
        );

        return $response;
    }

    /**
     * Gather the step middleware for the given agent, failing early when an entry does not honor the middleware contract.
     *
     * @return Middleware[]
     */
    protected function gatherMiddlewareFor(Agent $agent): array
    {
        $middleware = $agent instanceof HasMiddleware ? $agent->middleware() : [];

        foreach ($middleware as $pipe) {
            if (! $pipe instanceof Middleware) {
                throw new InvalidArgumentException(
                    'Agent middleware must extend ['.Middleware::class.'] and receive a ['.PendingStep::class.'].'
                );
            }
        }

        return $middleware;
    }

    /**
     * Get the conversation recorder for the given agent, if it remembers conversations.
     */
    protected function conversationRecorderFor(Agent $agent): ?RememberConversation
    {
        if (! in_array(RemembersConversations::class, class_uses_recursive($agent))) {
            return null;
        }

        /** @var Agent&RemembersConversationsContract $agent */
        return $agent->hasConversationParticipant()
            ? new RememberConversation(resolve(ConversationStore::class), $this)
            : null;
    }

    /**
     * Resolve the tools for the given agent, wrapping any agent instances as tools.
     */
    protected function resolveTools(Agent $agent): array
    {
        if (! $agent instanceof HasTools) {
            return [];
        }

        return array_map(
            fn ($tool) => $this->resolveTool($tool),
            [...$agent->tools()],
        );
    }

    /**
     * Resolve a tool returned by the agent into a native tool instance when needed.
     */
    protected function resolveTool(mixed $tool): mixed
    {
        return ToolResolver::resolve($tool);
    }

    /**
     * Listen for gateway tool invocations and dispatch events for the given agent when the tools are invoked.
     */
    protected function listenForToolInvocations(string $invocationId, Agent $agent): void
    {
        $this->textGenerationLoop()->onToolInvocation(
            invoking: function (Tool $tool, array $arguments) use ($invocationId, $agent): void {
                $this->currentToolInvocationId = (string) Str::uuid7();

                $this->events->dispatch(new InvokingTool(
                    $invocationId, $this->currentToolInvocationId, $agent, $tool, $arguments
                ));
            },
            invoked: function (Tool $tool, array $arguments, mixed $result) use ($invocationId, $agent): void {
                $this->events->dispatch(new ToolInvoked(
                    $invocationId, $this->currentToolInvocationId, $agent, $tool, $arguments, $result
                ));
            },
        );
    }
}
