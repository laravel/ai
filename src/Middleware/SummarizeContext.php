<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Agents\SummarizeConversationAgent;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;

class SummarizeContext
{
    protected ?string $summary = null;

    protected int $covered = 0;

    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected int $turns = 30,
        protected Lab|array|string|null $provider = null,
        protected ?string $model = null,
        protected ?int $timeout = null,
        protected int $ttl = 7 * 24 * 60 * 60,
    ) {}

    /**
     * Handle the pending generation step.
     */
    public function handle(PendingStep $step, Closure $next)
    {
        if ($step->isFirstStep()) {
            $this->compact($step);
        }

        if ($this->summary === null) {
            return $next($step);
        }

        return $next($step->withMessages([
            new UserMessage('Summary of the conversation up to this point:'.PHP_EOL.$this->summary),
            ...array_slice($step->messages, $this->covered),
        ]));
    }

    /**
     * Fold the history that fell out of the retained window into the summary.
     */
    protected function compact(PendingStep $step): void
    {
        [$this->summary, $this->covered] = $this->restore($step);

        $boundary = $this->boundary($step->messages);

        if ($boundary <= $this->covered) {
            return;
        }

        $displaced = array_slice($step->messages, $this->covered, $boundary - $this->covered);

        $this->summary = $this->summarize($step, $displaced);
        $this->covered = $boundary;

        $this->remember($step, $step->messages[$boundary - 1]);
    }

    /**
     * Restore the conversation's cached summary and the number of leading messages it covers.
     *
     * @return array{?string, int}
     */
    protected function restore(PendingStep $step): array
    {
        $key = $this->cacheKey($step);

        $cached = $key === null ? null : $this->cache()->get($key);

        if (! is_array($cached)) {
            return [null, 0];
        }

        $covered = $this->locate($step->messages, $cached['tail']);

        return $covered === null ? [null, 0] : [$cached['summary'], $covered];
    }

    /**
     * Cache the summary against the last message it covers.
     */
    protected function remember(PendingStep $step, Message $tail): void
    {
        if (($key = $this->cacheKey($step)) !== null) {
            $this->cache()->put($key, ['summary' => $this->summary, 'tail' => $this->fingerprint($tail)], $this->ttl);
        }
    }

    /**
     * Get the cache store summaries are kept in.
     */
    protected function cache(): Repository
    {
        return Cache::store(config('ai.caching.summaries.store'));
    }

    /**
     * Get the number of leading messages ending with the fingerprinted one, or null when it is no longer in the history.
     *
     * @param  Message[]  $messages
     */
    protected function locate(array $messages, string $fingerprint): ?int
    {
        // The earliest match wins so a repeated message re-summarizes history rather than skipping it...
        $index = (new Collection($messages))->search(fn (Message $message): bool => $this->fingerprint($message) === $fingerprint);

        return $index === false ? null : $index + 1;
    }

    /**
     * Get the cache key for the agent's conversation, or null when it has none.
     */
    protected function cacheKey(PendingStep $step): ?string
    {
        $agent = $step->options?->agent;

        if ($agent === null || ! RememberConversation::appliesTo($agent)) {
            return null;
        }

        /** @var Agent&RemembersConversations $agent */
        $conversation = $agent->currentConversation();

        return $conversation === null ? null : 'laravel-ai:summary:'.$conversation;
    }

    /**
     * Get the index the retained turns start at, or zero when the history holds fewer turns than are retained.
     *
     * @param  Message[]  $messages
     */
    protected function boundary(array $messages): int
    {
        $turns = (new Collection($messages))
            ->filter(fn (Message $message): bool => $message->role === MessageRole::User)
            ->keys();

        if ($turns->count() < $this->turns) {
            return 0;
        }

        return max($turns->slice(-$this->turns)->first(), $this->covered);
    }

    /**
     * Summarize the given messages, folding in any previous summary.
     *
     * @param  Message[]  $messages
     */
    protected function summarize(PendingStep $step, array $messages): string
    {
        return (new SummarizeConversationAgent)->prompt(
            $this->transcript($messages),
            provider: $this->provider ?? $step->provider,
            model: $this->model,
            timeout: $this->timeout,
        )->text;
    }

    /**
     * Get a fingerprint identifying the given message.
     */
    protected function fingerprint(Message $message): string
    {
        return md5($this->render($message));
    }

    /**
     * Render the given messages as plain text, led by the summary so far.
     *
     * @param  Message[]  $messages
     */
    protected function transcript(array $messages): string
    {
        return (new Collection($messages))
            ->map($this->render(...))
            ->when($this->summary, fn (Collection $lines): Collection => $lines->prepend('Summary so far: '.$this->summary))
            ->implode(PHP_EOL);
    }

    /**
     * Render the given message as a line of plain text.
     */
    protected function render(Message $message): string
    {
        return $message->role->value.': '.match (true) {
            $message instanceof ToolResultMessage => $message->toolResults->toJson(),
            $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty() => $message->content.' '.$message->toolCalls->toJson(),
            default => (string) $message->content,
        };
    }
}
