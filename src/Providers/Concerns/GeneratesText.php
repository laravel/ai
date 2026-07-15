<?php

namespace Laravel\Ai\Providers\Concerns;

use Closure;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\McpTool;

use function Laravel\Ai\pipeline;

trait GeneratesText
{
    protected string $currentToolInvocationId;

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

                $messages = [
                    ...($agent instanceof Conversational ? $agent->messages() : []),
                ];

                if ($prompt->resume === null) {
                    $messages[] = new UserMessage($prompt->prompt, $prompt->attachments->all());
                }

                $this->listenForToolInvocations($invocationId, $agent);

                $schema = $agent instanceof HasStructuredOutput ? $agent->schema(new JsonSchemaTypeFactory) : null;

                $response = $this->textGenerationLoop()->generate(
                    $this,
                    $prompt->model,
                    (string) $agent->instructions(),
                    $messages,
                    $this->resolveTools($agent),
                    $schema,
                    TextGenerationOptions::forAgent($agent),
                    $prompt->timeout,
                    $this->resumableApprovalFor($prompt),
                    $this->approvalResultRecorderFor($prompt),
                );

                if ($response->awaitingApproval()) {
                    ApprovalNotResumableException::throwUnlessResumable($agent);
                }

                $agentResponse = $response instanceof StructuredTextResponse
                    ? (new StructuredAgentResponse($invocationId, $response->structured, $response->text, $response->usage, $response->meta))
                        ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                        ->withSteps($response->steps)
                    : (new AgentResponse($invocationId, $response->text, $response->usage, $response->meta))
                        ->withMessages($response->messages)
                        ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                        ->withSteps($response->steps);

                $agentResponse->withPendingApprovals($response->pendingApprovals);

                return $agentResponse;
            });

        $this->events->dispatch(
            new AgentPrompted($invocationId, $processedPrompt ?? $prompt, $response)
        );

        if ($response->awaitingApproval()) {
            $this->events->dispatch(new ToolApprovalRequested(
                $invocationId,
                $prompt->agent,
                $response->pendingApprovals,
                $response->conversationId,
                $response->conversationUser,
            ));
        }

        return $response;
    }

    /**
     * Get the tool approval to resume with, unless the agent's gateway is faked.
     *
     * @return array<string, Decision>|null
     */
    protected function resumableApprovalFor(AgentPrompt $prompt): ?array
    {
        if ($prompt->resume === null || Ai::hasFakeGatewayFor($prompt->agent::class)) {
            return null;
        }

        return $prompt->resume;
    }

    /**
     * Get a callback that durably records resolved approval results before the run continues, if the store supports it.
     */
    protected function approvalResultRecorderFor(AgentPrompt $prompt): ?Closure
    {
        $agent = $prompt->agent;

        if ($prompt->resume === null
            || Ai::hasFakeGatewayFor($agent::class)
            || ! in_array(RemembersConversations::class, class_uses_recursive($agent))) {
            return null;
        }

        /** @var Agent&RemembersConversationsContract $agent */
        if ($agent->currentConversation() === null) {
            return null;
        }

        $store = app(ConversationStore::class);

        $conversationId = $agent->currentConversation();
        $participantId = $agent->conversationParticipant()?->id;

        return fn (array $toolResults) => $store->storeApprovalResults($conversationId, $participantId, $toolResults);
    }

    /**
     * Gather the middleware for the given agent.
     */
    protected function gatherMiddlewareFor(Agent $agent): array
    {
        $middleware = Ai::hasFakeGatewayFor($agent::class) ? [function (AgentPrompt $prompt, Closure $next) {
            Ai::recordPrompt($prompt);

            return $next($prompt);
        }] : [];

        if (in_array(RemembersConversations::class, class_uses_recursive($agent))) {
            /** @var Agent&RemembersConversationsContract $agent */
            if ($agent->hasConversationParticipant()) {
                $middleware[] = new RememberConversation(resolve(ConversationStore::class), $this);
            }
        }

        return $agent instanceof HasMiddleware
            ? [...$middleware, ...$agent->middleware()]
            : $middleware;
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
        return match (true) {
            $tool instanceof Agent => new AgentTool($tool),
            $tool instanceof Tool => $tool,
            McpTool::supports($tool) => new McpTool($tool),
            McpServerTool::supports($tool) => new McpServerTool($tool),
            default => $tool,
        };
    }

    /**
     * Listen for gateway tool invocations and dispatch events for the given agent when the tools are invoked.
     */
    protected function listenForToolInvocations(string $invocationId, Agent $agent): void
    {
        $this->textGenerationLoop()->onToolInvocation(
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
