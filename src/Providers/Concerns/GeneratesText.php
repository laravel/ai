<?php

namespace Laravel\Ai\Providers\Concerns;

use Closure;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Gateway\RunContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\McpTool;
use Throwable;

use function Laravel\Ai\pipeline;

trait GeneratesText
{
    use ResumesToolApprovals;

    /**
     * Invoke the given agent.
     */
    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        $invocationId = $prompt->invocationId ?? (string) Str::uuid7();

        $processedPrompt = null;
        $resolvedApprovalResults = null;

        try {
            $response = pipeline()
                ->send($prompt)
                ->through($this->gatherMiddlewareFor($prompt->agent))
                ->then(function (AgentPrompt $prompt) use ($invocationId, &$processedPrompt, &$resolvedApprovalResults): TextResponse {
                    $processedPrompt = $prompt;

                    $this->events->dispatch(new PromptingAgent($invocationId, $prompt));

                    $agent = $prompt->agent;

                    $messages = $this->withoutForeignProviderContentBlocks([
                        ...($prompt->messages ?? []),
                        ...($agent instanceof Conversational ? $agent->messages() : []),
                    ]);

                    if (! $prompt->hasApprovalDecisions()) {
                        $messages[] = new UserMessage($prompt->prompt, $prompt->attachments->all());
                    }

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
                        $this->approvalResultRecorderFor($prompt, $resolvedApprovalResults),
                        $this->runContextFor($invocationId, $prompt),
                    );

                    if ($response->hasPendingApprovals()) {
                        $this->throwIfNotResumable($prompt);
                    }

                    $agentResponse = $response instanceof StructuredTextResponse
                        ? (new StructuredAgentResponse($invocationId, $response->structured, $response->text, $response->usage, $response->meta))
                            ->withMessages($response->messages)
                            ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                            ->withSteps($response->steps)
                            ->withRawResponse($response->raw)
                        : (new AgentResponse($invocationId, $response->text, $response->usage, $response->meta))
                            ->withMessages($response->messages)
                            ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                            ->withSteps($response->steps)
                            ->withRawResponse($response->raw);

                    $agentResponse->withPendingApprovals($response->pendingApprovals);

                    return $agentResponse;
                });
        } catch (Throwable $exception) {
            $this->recordAgentFailure($invocationId, $prompt, $exception, $processedPrompt);

            throw $exception;
        }

        $this->events->dispatch(
            new AgentPrompted($invocationId, $processedPrompt ?? $prompt, $response)
        );

        if ($response->hasPendingApprovals()) {
            $this->events->dispatch(new ToolApprovalRequested(
                $invocationId,
                $prompt->agent,
                $response->pendingApprovals,
                $response->conversationId,
                $response->conversationUser,
            ));
        }

        if ($resolvedApprovalResults !== null) {
            $this->events->dispatch(new ToolApprovalResolved(
                $invocationId,
                $prompt->agent,
                $resolvedApprovalResults,
                $response->conversationId,
                $response->conversationUser,
            ));
        }

        return $response;
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
            $middleware[] = new RememberConversation(resolve(ConversationStore::class), $this);
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
            $tool instanceof ToolSearch => $tool->withTools(
                array_map(fn ($nested) => $this->resolveTool($nested), $tool->tools),
            ),
            McpTool::supports($tool) => new McpTool($tool),
            McpServerTool::supports($tool) => new McpServerTool($tool),
            default => $tool,
        };
    }

    /**
     * Build the context that identifies this run and reports its step and tool events.
     */
    protected function runContextFor(string $invocationId, AgentPrompt $prompt): RunContext
    {
        return new RunContext($invocationId, $prompt->agent, $this, $prompt->model, $this->events);
    }

    /**
     * Dispatch the terminal failure event for a run, unless the caller may still retry it against another provider.
     */
    protected function recordAgentFailure(string $invocationId, AgentPrompt $prompt, Throwable $exception, ?AgentPrompt $processedPrompt = null, bool $retryable = true): void
    {
        // A failoverable exception is only terminal once the caller has run out of providers to try...
        if ($retryable &&
            ! $prompt->isFinalAttempt() &&
            $exception instanceof FailoverableException) {
            return;
        }

        $this->events->dispatch(
            new AgentFailed($invocationId, $processedPrompt ?? $prompt, $exception)
        );
    }

    /**
     * Throw when a pause has surfaced on a prompt that cannot be resumed from persisted or replayed history.
     */
    protected function throwIfNotResumable(AgentPrompt $prompt): void
    {
        // A conversational agent replays from the database; an ad-hoc history (even an empty first turn) replays from the client...
        if (! ($prompt->agent instanceof Conversational || $prompt->messages !== null)) {
            throw ApprovalNotResumableException::make();
        }
    }
}
