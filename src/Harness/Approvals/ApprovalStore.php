<?php

namespace Laravel\Ai\Harness\Approvals;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Responses\Data\ToolResult;
use LogicException;

use function Laravel\Ai\ulid;

class ApprovalStore
{
    public function __construct(protected Repository $cache, protected int $ttl = 3600) {}

    public function lock(string $name, int $seconds): Lock
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Harness runs require a cache store supporting atomic locks.');
        }

        return $store->lock($name, $seconds);
    }

    protected function key(HarnessRun $run): string
    {
        return 'ai:harness:approvals:'.hash('sha256', $run->agent.'|'.$run->harness.'|'.$run->sessionId);
    }

    protected function hash(string $tool, array $input): string
    {
        return hash('sha256', $tool.'|'.json_encode($input, JSON_THROW_ON_ERROR));
    }

    public function check(HarnessRun $run, string $tool, array $input, ?string $reason = null): array
    {
        return $this->lock($this->key($run).':lock', 10)->block(5, function () use ($run, $tool, $input, $reason) {
            $state = $this->cache->get($this->key($run), ['pending' => [], 'decisions' => []]);
            $hash = $this->hash($tool, $input);

            if (isset($state['decisions'][$hash])) {
                return $state['decisions'][$hash];
            }

            $pending = $state['pending'][$hash] ?? new PendingApproval(ulid(), $tool, $input, $reason);
            $state['pending'][$hash] = $pending;
            $this->cache->put($this->key($run), $state, $this->ttl);

            return ['behavior' => 'deny', 'message' => 'Awaiting user approval. End this turn and wait for a decision.', 'pending_id' => $pending->id];
        });
    }

    public function remember(HarnessRun $run, Collection $approvals): void
    {
        $this->lock($this->key($run).':lock', 10)->block(5, function () use ($run, $approvals) {
            $state = $this->cache->get($this->key($run), ['pending' => [], 'decisions' => []]);

            foreach ($approvals as $approval) {
                $state['pending'][$this->hash($approval->tool, $approval->arguments)] = $approval;
            }

            $this->cache->put($this->key($run), $state, $this->ttl);
        });
    }

    public function pending(HarnessRun $run): Collection
    {
        return new Collection(array_values($this->cache->get($this->key($run), [])['pending'] ?? []));
    }

    public function resolve(HarnessRun $run, Decisions $decisions): Collection
    {
        return $this->lock($this->key($run).':lock', 10)->block(5, function () use ($run, $decisions) {
            $state = $this->cache->get($this->key($run), ['pending' => [], 'decisions' => []]);
            $pending = new Collection($state['pending']);

            if ($pending->isEmpty() || array_diff(array_keys($decisions->all()), [...$pending->pluck('id')->all(), '*'])) {
                throw new LogicException('No matching pending approvals exist for this harness session, or they have expired.');
            }

            $results = new Collection;

            foreach ($state['pending'] as $hash => $approval) {
                $decision = $decisions->get($approval->id) ?? $decisions->get('*');

                if ($decision === null) {
                    continue;
                }

                $payload = $decision->isRejected()
                    ? ['behavior' => 'deny', 'message' => $decision->result ?? 'The user rejected this tool call.']
                    : ['behavior' => 'allow', 'updatedInput' => $decision->arguments ?? $approval->arguments];

                $state['decisions'][$hash] = $payload;

                if ($decision->isEdited()) {
                    $state['decisions'][$this->hash($approval->tool, $decision->arguments)] = $payload;
                }

                unset($state['pending'][$hash]);
                $results->push(new ToolResult($approval->id, $approval->tool, $decision->arguments ?? $approval->arguments, $payload, denied: $decision->isRejected()));
            }

            $this->cache->put($this->key($run), $state, $this->ttl);

            return $results;
        });
    }

    public function clearDecisions(HarnessRun $run): void
    {
        $this->lock($this->key($run).':lock', 10)->block(5, function () use ($run) {
            $state = $this->cache->get($this->key($run), ['pending' => []]);
            $state['decisions'] = [];
            $this->cache->put($this->key($run), $state, $this->ttl);
        });
    }
}
