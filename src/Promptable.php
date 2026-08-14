<?php

namespace Laravel\Ai;

use Closure;
use Generator;
use Illuminate\Broadcasting\Channel;
use Illuminate\Container\Container;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Attributes\Model as ModelAttribute;
use Laravel\Ai\Attributes\Provider as ProviderAttribute;
use Laravel\Ai\Attributes\Timeout as TimeoutAttribute;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Attributes\WithoutBroadcasting;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Jobs\BroadcastAgent;
use Laravel\Ai\Jobs\InvokeAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use ReflectionClass;
use RuntimeException;

trait Promptable
{
    use SerializesModels;

    /**
     * The invocation id supplied by the caller for the agent's next run, if any.
     */
    protected ?string $pendingInvocationId = null;

    /**
     * Use the given invocation id for the agent's next run instead of a generated one.
     *
     * The id identifies the run in every event it dispatches, so a caller that
     * already records its own work may reuse that record's identifier here.
     */
    public function withInvocationId(string $invocationId): static
    {
        $this->pendingInvocationId = $invocationId;

        return $this;
    }

    /**
     * Get the invocation id for a run, consuming any the caller supplied.
     */
    protected function resolveInvocationId(): string
    {
        $invocationId = $this->pendingInvocationId ?? (string) Str::uuid7();

        // Consumed rather than retained, so a reused agent instance cannot
        // silently stamp a later run with an earlier run's identifier.
        $this->pendingInvocationId = null;

        return $invocationId;
    }

    /**
     * Create a new instance of the agent.
     */
    public static function make(...$arguments): static
    {
        return match (true) {
            $arguments !== [] && ! array_is_list($arguments) => Container::getInstance()->makeWith(static::class, $arguments),
            $arguments !== [] => new static(...$arguments),
            default => Container::getInstance()->make(static::class),
        };
    }

    /**
     * Invoke the agent with a given prompt, or resume a paused run with tool approval decisions.
     */
    public function prompt(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null): AgentResponse
    {
        [$text, $approvalDecisions] = $this->extractPromptInput($prompt);

        $invocationId = $this->resolveInvocationId();

        $providers = $approvalDecisions !== null
            ? $this->providersForApprovalContinuation($provider, $model)
            : $this->getProvidersAndModelsForFailover($provider, $model);

        [$parentInvocationId, $parentToolInvocationId] = ParentInvocation::current();

        $run = function (TextProvider $provider, string $model, bool $isFinalAttempt = true) use (
            $text, $attachments, $timeout, $invocationId, $approvalDecisions,
            $parentInvocationId, $parentToolInvocationId
        ): AgentResponse {
            return $provider->prompt(
                new AgentPrompt(
                    $this, $text, $attachments, $provider, $model, $this->getTimeout($timeout),
                    invocationId: $invocationId,
                    approvalDecisions: $approvalDecisions,
                    parentInvocationId: $parentInvocationId,
                    parentToolInvocationId: $parentToolInvocationId,
                    isFinalAttempt: $isFinalAttempt,
                )
            );
        };

        if ($approvalDecisions !== null) {
            [$provider, $model] = $this->iterateProvidersWithFailover($providers)->current();

            return $run($provider, $model);
        }

        return $this->withModelFailover($run, $providers, $invocationId);
    }

    /**
     * Invoke the agent with a given prompt and return a streamable response.
     */
    public function stream(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null): StreamableAgentResponse
    {
        [$text, $approvalDecisions] = $this->extractPromptInput($prompt);

        return $this->streamPrompt($text, $approvalDecisions, $attachments, $provider, $model, $timeout);
    }

    /**
     * Stream a text prompt or an approval continuation through the configured providers.
     */
    private function streamPrompt(
        string $prompt,
        ?Decisions $approvalDecisions,
        array $attachments,
        Lab|array|string|null $provider,
        ?string $model,
        ?int $timeout): StreamableAgentResponse
    {
        $providers = $approvalDecisions !== null
            ? $this->providersForApprovalContinuation($provider, $model)
            : $this->getProvidersAndModelsForFailover($provider, $model);
        $resolvedTimeout = $this->getTimeout($timeout);

        $invocationId = $this->resolveInvocationId();

        [$parentInvocationId, $parentToolInvocationId] = ParentInvocation::current();

        if (count($providers) === 1) {
            [$resolved, $resolvedModel] = $this->iterateProvidersWithFailover($providers)->current();

            return $resolved->stream(
                new AgentPrompt(
                    $this, $prompt, $attachments, $resolved, $resolvedModel, $resolvedTimeout,
                    invocationId: $invocationId,
                    approvalDecisions: $approvalDecisions,
                    parentInvocationId: $parentInvocationId,
                    parentToolInvocationId: $parentToolInvocationId,
                )
            );
        }

        $meta = new Meta;
        $outer = null;

        $outer = new StreamableAgentResponse(
            $invocationId,
            function () use ($providers, $prompt, $approvalDecisions, $attachments, $resolvedTimeout, $invocationId, $parentInvocationId, $parentToolInvocationId, &$outer) {
                $lastException = null;

                foreach ($this->iterateProvidersWithFailover($providers) as [$provider, $model, $isFinalAttempt]) {
                    $innerResponse = null;

                    try {
                        $innerResponse = $provider->stream(
                            new AgentPrompt(
                                $this, $prompt, $attachments, $provider, $model, $resolvedTimeout,
                                invocationId: $invocationId,
                                approvalDecisions: $approvalDecisions,
                                parentInvocationId: $parentInvocationId,
                                parentToolInvocationId: $parentToolInvocationId,
                                isFinalAttempt: $isFinalAttempt,
                            )
                        );

                        $innerResponse->then(fn (StreamedAgentResponse $response): StreamableAgentResponse => $outer->adoptStateFrom($response));

                        foreach ($innerResponse as $event) {
                            yield $event;
                        }

                        return;
                    } catch (FailoverableException $e) {
                        if ($innerResponse?->hasYielded()) {
                            throw $e;
                        }

                        $lastException = $isFinalAttempt
                            ? $e
                            : $this->recordAgentFailover($invocationId, $provider, $model, $e);
                    }
                }

                throw $lastException;
            },
            $meta,
        );

        return $outer;
    }

    /**
     * Invoke the agent in a queued job.
     */
    public function queue(Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        if (static::isFaked()) {
            Ai::recordPrompt(
                new QueuedAgentPrompt($this, $prompt, $attachments, $provider, $model),
            );
        }

        return new QueuedAgentResponse(
            InvokeAgent::dispatch($this, $prompt, $attachments, $provider, $model)
        );
    }

    /**
     * Split a prompt input into its text and tool approval decisions.
     *
     * @return array{string, ?Decisions}
     */
    private function extractPromptInput(Decisions|string $prompt): array
    {
        if (is_string($prompt)) {
            return [$prompt, null];
        }

        return ['', $prompt];
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events.
     */
    public function broadcast(Decisions|string $prompt, Channel|array $channels, array $attachments = [], bool $now = false, Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        $without = WithoutBroadcasting::eventsFor($this);

        return $this->stream($prompt, $attachments, $provider, $model)
            ->each(function (StreamEvent $event) use ($channels, $now, $without): void {
                if (WithoutBroadcasting::excludes($without, $event)) {
                    return;
                }

                $event->{$now ? 'broadcastNow' : 'broadcast'}($channels);
            });
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events immediately.
     */
    public function broadcastNow(Decisions|string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): StreamableAgentResponse
    {
        return $this->broadcast($prompt, $channels, $attachments, now: true, provider: $provider, model: $model);
    }

    /**
     * Invoke the agent with a given prompt and broadcast the streamed events.
     */
    public function broadcastOnQueue(Decisions|string $prompt, Channel|array $channels, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null): QueuedAgentResponse
    {
        if (static::isFaked()) {
            Ai::recordPrompt(
                new QueuedAgentPrompt($this, $prompt, $attachments, $provider, $model),
            );
        }

        return new QueuedAgentResponse(
            BroadcastAgent::dispatch($this, $prompt, $channels, $attachments, $provider, $model)
        );
    }

    /**
     * Invoke the given Closure with provider / model failover.
     *
     * @param  array<string, string|null>  $providers
     */
    private function withModelFailover(Closure $callback, array $providers, string $invocationId): mixed
    {
        $lastException = null;

        foreach ($this->iterateProvidersWithFailover($providers) as [$provider, $model, $isFinalAttempt]) {
            try {
                return $callback($provider, $model, $isFinalAttempt);
            } catch (FailoverableException $e) {
                $lastException = $isFinalAttempt
                    ? $e
                    : $this->recordAgentFailover($invocationId, $provider, $model, $e);
            }
        }

        throw $lastException;
    }

    /**
     * Get the configured providers and models for failover.
     */
    private function getProvidersAndModelsForFailover(Lab|array|string|null $provider, ?string $model): array
    {
        $providers = $this->getProvidersAndModels($provider, $model);

        if (empty($providers)) {
            throw new RuntimeException('No AI providers were configured.');
        }

        return $providers;
    }

    /**
     * Get the single provider / model pair an approval continuation must run against, since it may not fail over to a different provider.
     */
    private function providersForApprovalContinuation(Lab|array|string|null $provider, ?string $model): array
    {
        return array_slice($this->getProvidersAndModelsForFailover($provider, $model), 0, 1, true);
    }

    /**
     * Iterate the configured provider / model pairs, flagging the attempt that has no provider left to fall back to.
     *
     * @param  array<string, string|null>  $providers
     * @return Generator<int, array{TextProvider, string, bool}>
     */
    private function iterateProvidersWithFailover(array $providers): Generator
    {
        $remaining = count($providers);

        foreach ($providers as $provider => $model) {
            $provider = Ai::textProviderFor($this, $provider);

            yield [$provider, $model ?? $this->getDefaultModelFor($provider), --$remaining === 0];
        }
    }

    /**
     * Record that an agent failed over to the next configured provider.
     */
    private function recordAgentFailover(string $invocationId, Provider $provider, string $model, FailoverableException $exception): FailoverableException
    {
        event(new AgentFailedOver($invocationId, $this, $provider, $model, $exception));

        return $exception;
    }

    /**
     * Get the providers and models array for the given initial provider and model values.
     */
    protected function getProvidersAndModels(Lab|array|string|null $provider, ?string $model): array
    {
        if (is_null($provider)) {
            if (method_exists($this, 'provider')) {
                $provider = $this->provider();
            } else {
                $attributes = (new ReflectionClass($this))->getAttributes(ProviderAttribute::class);

                $provider = $attributes === [] ? null : $attributes[0]->newInstance()->value;
            }
        }

        if (! is_array($provider) && is_null($model)) {
            if (method_exists($this, 'model')) {
                $model = $this->model();
            } else {
                $attributes = (new ReflectionClass($this))->getAttributes(ModelAttribute::class);

                $model = $attributes === [] ? null : $attributes[0]->newInstance()->value;
            }
        }

        $resolved = $provider ?? config('ai.default');

        if (is_array($resolved) && array_intersect(array_keys($resolved), ['text', 'image', 'audio', 'transcription', 'embedding', 'reranking'])) {
            throw new InvalidArgumentException('The "ai.default" config value must be a string provider name or a Lab enum, not an array.');
        }

        return Provider::formatProviderAndModelList($resolved, $model);
    }

    /**
     * Get the default model to use for the given provider.
     */
    protected function getDefaultModelFor(TextProvider $provider): string
    {
        $reflection = new ReflectionClass($this);

        if (! empty($reflection->getAttributes(UseSmartestModel::class))) {
            return $provider->smartestTextModel();
        }

        if (! empty($reflection->getAttributes(UseCheapestModel::class))) {
            return $provider->cheapestTextModel();
        }

        return $provider->defaultTextModel();
    }

    /**
     * Get the timeout to use for the agent prompt.
     */
    protected function getTimeout(?int $timeout): int
    {
        if (! is_null($timeout)) {
            return $timeout;
        }

        if (method_exists($this, 'timeout')) {
            return $this->timeout();
        }

        $attributes = (new ReflectionClass($this))->getAttributes(TimeoutAttribute::class);

        if ($attributes !== []) {
            return $attributes[0]->newInstance()->value;
        }

        return 60;
    }

    /**
     * Fake the responses returned by the agent.
     */
    public static function fake(Closure|array $responses = []): FakeTextGateway
    {
        return Ai::fakeAgent(static::class, $responses);
    }

    /**
     * Assert that a prompt was received matching a given truth test.
     */
    public static function assertPrompted(Closure|string $callback): void
    {
        Ai::assertAgentWasPrompted(static::class, $callback);
    }

    /**
     * Assert that a prompt was not received matching a given truth test.
     */
    public static function assertNotPrompted(Closure|string $callback): void
    {
        Ai::assertAgentNotPrompted(static::class, $callback);
    }

    /**
     * Assert that no prompts were received.
     */
    public static function assertNeverPrompted(): void
    {
        Ai::assertAgentNeverPrompted(static::class);
    }

    /**
     * Assert that a queued prompt was received matching a given truth test.
     */
    public static function assertQueued(Closure|string $callback): void
    {
        Ai::assertAgentWasQueued(static::class, $callback);
    }

    /**
     * Assert that a queued prompt was not received matching a given truth test.
     */
    public static function assertNotQueued(Closure|string $callback): void
    {
        Ai::assertAgentNotQueued(static::class, $callback);
    }

    /**
     * Assert that no queued prompts were received.
     */
    public static function assertNeverQueued(): void
    {
        Ai::assertAgentNeverQueued(static::class);
    }

    /**
     * Determine if the agent is currently faked.
     */
    public static function isFaked(): bool
    {
        return Ai::hasFakeGatewayFor(static::class);
    }
}
