<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\Attributes\Sandbox as SandboxAttribute;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Contracts\Sandbox\ForgetsSandboxes;
use Laravel\Ai\Events\SandboxResolved;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Sandboxes\Exceptions\SandboxBusy;
use Laravel\Ai\Sandboxes\Sandbox;
use Laravel\Ai\Sandboxes\SandboxManager;
use Laravel\Ai\Tools\Sandbox\SandboxTool;
use LogicException;
use Throwable;

class ResolveSandbox
{
    /**
     * The number of seconds a turn may hold its sandbox before another turn can take it.
     */
    protected const LOCK_SECONDS = 600;

    public function __construct(
        protected SandboxManager $sandboxes,
        protected Dispatcher $events,
    ) {}

    /**
     * Determine whether the given agent works in a sandbox.
     */
    public static function appliesTo(Agent $agent): bool
    {
        return SandboxAttribute::on($agent) !== null;
    }

    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $attribute = SandboxAttribute::on($prompt->agent);

        // A paused turn resumes in a later request, which can only find the sandbox again through the stored conversation...
        if ($attribute->approveCommands && ! RememberConversation::appliesTo($prompt->agent)) {
            throw new LogicException(sprintf(
                'Sandboxed agent [%s] must remember conversations to approve commands. Use the RemembersConversations trait or #[Sandbox(approveCommands: false)].',
                $prompt->agent::class,
            ));
        }

        $factory = $this->sandboxes->factory($attribute->name);

        $id = null;
        $lock = null;

        // Resolved on first use, after the conversation middleware has named the conversation this turn belongs to...
        $sandbox = Sandbox::defer(function () use ($prompt, $factory, $attribute, &$id, &$lock): Sandbox {
            $id ??= $prompt->conversationId() ?? (string) Str::ulid();

            if ($lock === null) {
                // ponytail: fixed lease, a turn running past it lets the next turn in; renew per tool call if that bites
                $candidate = Cache::lock("ai:sandbox:{$id}", static::LOCK_SECONDS);

                if (! $candidate->get()) {
                    throw SandboxBusy::for($id);
                }

                $lock = $candidate;
            }

            $sandbox = $factory->create($id);

            if ($attribute->cwd !== null) {
                $sandbox->mkdir($attribute->cwd, recursive: true);

                $sandbox = $sandbox->withCwd($attribute->cwd);
            }

            $this->events->dispatch(new SandboxResolved($prompt->agent, $id, $sandbox));

            return $sandbox;
        });

        $prompt->setSandbox(
            $sandbox,
            $factory->tools($sandbox) ?? SandboxTool::defaults($sandbox, $attribute->approveCommands),
        );

        $release = function () use ($prompt, $factory, &$id, &$lock): void {
            $lock?->release();

            if ($lock !== null && ! $this->kept($prompt->agent, $id) && $factory instanceof ForgetsSandboxes) {
                $factory->forget($id);
            }
        };

        try {
            $response = $next($prompt);
        } catch (Throwable $exception) {
            $release();

            throw $exception;
        }

        return $response instanceof StreamableAgentResponse
            ? $response->finally($release)
            : $response->then(fn () => $release());
    }

    /**
     * Determine whether the sandbox outlives the turn because the conversation it belongs to was stored.
     */
    protected function kept(Agent $agent, string $id): bool
    {
        /** @var Agent&RemembersConversations $agent */
        return RememberConversation::appliesTo($agent) && $agent->currentConversation() === $id;
    }
}
