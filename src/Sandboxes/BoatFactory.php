<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\Checkpointable;
use Laravel\Ai\Contracts\Sandbox\ForgetsSandboxes;
use Laravel\Ai\Contracts\Sandbox\SandboxFactory;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Sandboxes\Drivers\BoatDriver;
use RuntimeException;

class BoatFactory implements Checkpointable, ForgetsSandboxes, SandboxFactory, Suspendable
{
    /**
     * The directory Boat sandboxes work in, and the only one besides /tmp its file API reaches.
     */
    public const WORKDIR = '/home/user';

    /**
     * The states of a sandbox that accepts commands.
     */
    protected const READY = ['ready', 'idle', 'running'];

    public function __construct(protected array $config) {}

    /**
     * {@inheritdoc}
     */
    public function create(string $id): Sandbox
    {
        $sandbox = ($boat = $this->boat($id)) ? $this->find($boat) : null;

        if ($sandbox === null || in_array($sandbox['state'], ['error', 'cancelled'], true)) {
            $sandbox = $this->provision($id);
        }

        if ($sandbox['state'] === 'archiving') {
            $sandbox = $this->wait($sandbox['id'], ['archived']);
        }

        if ($sandbox['state'] === 'archived') {
            $this->http()->post("sandboxes/{$sandbox['id']}/resume")->throw();
        }

        if (! in_array($sandbox['state'], static::READY, true)) {
            $this->wait($sandbox['id'], static::READY);
        }

        return Sandbox::fromDriver(new BoatDriver($sandbox['id'], $this->config), static::WORKDIR);
    }

    /**
     * {@inheritdoc}
     */
    public function tools(Sandbox $sandbox): ?array
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function checkpoint(string $id): string
    {
        return Sandbox::exclusively($id, function () use ($id): string {
            $boat = $this->boat($id) ?? throw new RuntimeException("Sandbox [{$id}] does not exist.");

            $this->http()->post('named-snapshots', [
                'sandboxId' => $boat,
                'name' => $checkpoint = $this->prefix($id).strtolower((string) Str::ulid()),
            ])->throw();

            $this->waitForSnapshot($checkpoint);

            return $checkpoint;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function restore(string $id, string $checkpoint): void
    {
        Sandbox::exclusively($id, function () use ($id, $checkpoint): void {
            $this->waitForSnapshot($checkpoint);

            $previous = $this->boat($id);

            // Boat cannot roll a sandbox back in place, so the conversation moves to a new sandbox started from the snapshot...
            $restored = $this->provision($id, ['from' => $checkpoint]);

            if ($previous !== null) {
                $this->delete($previous);
            }

            $this->wait($restored['id'], static::READY);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function forgetCheckpoint(string $id, string $checkpoint): void
    {
        $this->http()->delete("named-snapshots/{$checkpoint}");
    }

    /**
     * {@inheritdoc}
     */
    public function suspend(string $id): void
    {
        if ($boat = $this->boat($id)) {
            $this->http()->post("sandboxes/{$boat}/stop")->throw();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function suspendsAfterTurn(): bool
    {
        return (bool) ($this->config['suspend_after_turn'] ?? false);
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $id): void
    {
        if ($boat = $this->boat($id)) {
            $this->delete($boat);
        }

        collect($this->http()->get('named-snapshots')->json('snapshots', []))
            ->pluck('name')
            ->filter(fn ($name) => str_starts_with($name, $this->prefix($id)))
            ->each(fn ($name) => $this->forgetCheckpoint($id, $name));

        Cache::forget($this->key($id));
    }

    /**
     * Get the ID of the Boat sandbox the sandbox with the given ID runs in.
     */
    public function boat(string $id): ?string
    {
        return Cache::get($this->key($id));
    }

    /**
     * Create a Boat sandbox for the sandbox with the given ID.
     *
     * @return array<string, mixed>
     */
    protected function provision(string $id, array $options = []): array
    {
        $sandbox = $this->http()->post('sandboxes', array_filter([
            'type' => $this->config['type'] ?? 'small',
            'ttlSeconds' => $this->config['ttl'] ?? 900,
            'noEnv' => $this->config['no_env'] ?? true,
            'env' => $this->config['env'] ?? [],
            'environment' => $this->config['environment'] ?? null,
            ...$options,
        ], fn ($value) => $value !== null && $value !== []))->throw()->json('sandbox');

        Cache::forever($this->key($id), $sandbox['id']);

        return $sandbox;
    }

    /**
     * Get the Boat sandbox with the given ID, or null when it no longer exists.
     *
     * @return array<string, mixed>|null
     */
    protected function find(string $boat): ?array
    {
        $response = $this->http()->get("sandboxes/{$boat}");

        return $response->notFound() ? null : $response->throw()->json('sandbox');
    }

    /**
     * Wait for the Boat sandbox with the given ID to reach one of the given states.
     *
     * @param  array<int, string>  $states
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    protected function wait(string $boat, array $states): array
    {
        $deadline = now()->addSeconds($this->config['start_timeout'] ?? 300);

        while (true) {
            $sandbox = $this->find($boat) ?? throw new RuntimeException("Boat sandbox [{$boat}] no longer exists.");

            if (in_array($sandbox['state'], $states, true)) {
                return $sandbox;
            }

            if (in_array($sandbox['state'], ['error', 'cancelled'], true) || now()->isAfter($deadline)) {
                throw new RuntimeException("Boat sandbox [{$boat}] is {$sandbox['state']}: ".($sandbox['error'] ?? 'it did not become ready in time.'));
            }

            Sleep::for(2)->seconds();
        }
    }

    /**
     * Wait for the named snapshot to finish saving.
     *
     * @throws RuntimeException
     */
    protected function waitForSnapshot(string $name): void
    {
        $deadline = now()->addSeconds($this->config['snapshot_timeout'] ?? 600);

        while (true) {
            $snapshot = $this->http()->get("named-snapshots/{$name}")->throw()->json('snapshot');

            if ($snapshot['status'] === 'ready') {
                return;
            }

            if ($snapshot['status'] === 'failed' || now()->isAfter($deadline)) {
                throw new RuntimeException("Boat snapshot [{$name}] did not save: ".($snapshot['error'] ?? 'it timed out.'));
            }

            Sleep::for(2)->seconds();
        }
    }

    /**
     * Permanently delete the Boat sandbox with the given ID.
     */
    protected function delete(string $boat): void
    {
        $this->http()->withHeaders(['X-Ascii-Confirm-Delete' => $boat])->delete("sandboxes/{$boat}");
    }

    /**
     * Get the prefix of the snapshot names of the sandbox with the given ID, which Boat requires to be lowercase.
     */
    protected function prefix(string $id): string
    {
        return 'ai-'.substr(hash('xxh128', $id), 0, 12).'-';
    }

    /**
     * Get the cache key mapping the sandbox with the given ID to its Boat sandbox.
     */
    protected function key(string $id): string
    {
        return "ai:sandbox:boat:{$id}";
    }

    /**
     * Create an HTTP client for the Boat API.
     */
    protected function http(): PendingRequest
    {
        return BoatDriver::client($this->config);
    }
}
