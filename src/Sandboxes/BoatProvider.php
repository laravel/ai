<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\Checkpointable;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Sandboxes\Drivers\BoatDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use Laravel\Ai\Sandboxes\Exceptions\UnsupportedOptionException;

class BoatProvider extends Provider implements Checkpointable, Suspendable
{
    /**
     * The directory Boat sandboxes work in, and the only one besides /tmp its file API reaches.
     */
    public const WORKDIR = '/home/user';

    /**
     * {@inheritdoc}
     */
    protected function supportedOptions(): array
    {
        return ['image', 'env', 'ttl', 'network', 'type'];
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $options = []): Sandbox
    {
        $options = $this->validate($options);

        if (($options['network'] ?? true) === false) {
            throw UnsupportedOptionException::for($this->name(), ['network'], 'Boat sandboxes always have network access.');
        }

        $sandbox = $this->provision(array_filter([
            'from' => $options['image'] ?? null,
            'type' => $options['type'] ?? null,
            'ttlSeconds' => $options['ttl'] ?? null,
            'env' => $options['env'] ?? null,
        ], fn ($value) => $value !== null));

        return $this->handle($this->wait($sandbox['id'], SandboxState::Running)['id']);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): Sandbox
    {
        $sandbox = $this->valid($id) ? $this->find($id) : null;

        if ($sandbox === null || $sandbox['state'] === 'cancelled') {
            throw SandboxNotFound::for($this->name(), $id);
        }

        return $this->handle($id);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $id): void
    {
        if (! $this->valid($id)) {
            return;
        }

        $this->destroy($id);

        foreach ($this->checkpoints($id) as $checkpoint) {
            $this->forgetCheckpoint($id, $checkpoint);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function suspend(string $id): void
    {
        $this->get($id);

        $this->ensureSuccessful($this->http()->post("sandboxes/{$id}/stop"), $id);
    }

    /**
     * {@inheritdoc}
     */
    public function resume(string $id): void
    {
        $sandbox = ($this->valid($id) ? $this->find($id) : null) ?? throw SandboxNotFound::for($this->name(), $id);

        if (($state = BoatDriver::mapState($sandbox['state'])) !== SandboxState::Stopped) {
            throw SandboxStateException::for($this->name(), $id, $state, SandboxState::Stopped);
        }

        if ($sandbox['state'] === 'archiving') {
            $this->wait($id, SandboxState::Stopped, ['archived']);
        }

        $this->ensureSuccessful($this->http()->post("sandboxes/{$id}/resume"), $id);

        $this->wait($id, SandboxState::Running);
    }

    /**
     * {@inheritdoc}
     */
    public function checkpoint(string $id): string
    {
        $this->get($id);

        $this->ensureSuccessful($this->http()->post('named-snapshots', [
            'sandboxId' => $id,
            'name' => $checkpoint = $this->prefix($id).strtolower((string) Str::ulid()),
        ]), $id);

        $this->waitForSnapshot($id, $checkpoint);

        return $checkpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function restore(string $id, string $checkpoint): Sandbox
    {
        $this->waitForSnapshot($id, $checkpoint);

        // Boat cannot roll a sandbox back in place, so the restored sandbox is a new one with its own ID...
        $restored = $this->provision(['from' => $checkpoint]);

        $this->destroy($id);

        return $this->handle($this->wait($restored['id'], SandboxState::Running)['id']);
    }

    /**
     * {@inheritdoc}
     */
    public function forgetCheckpoint(string $id, string $checkpoint): void
    {
        $response = $this->http()->delete("named-snapshots/{$checkpoint}");

        if (! $response->notFound()) {
            $this->ensureSuccessful($response, $id);
        }
    }

    /**
     * Permanently delete the Boat sandbox with the given ID, which Boat finishes in the background.
     */
    protected function destroy(string $id): void
    {
        $response = $this->http()->withHeaders(['X-Ascii-Confirm-Delete' => $id])->delete("sandboxes/{$id}");

        if (! $response->notFound()) {
            $this->ensureSuccessful($response, $id);
        }
    }

    /**
     * Create a handle for the Boat sandbox with the given ID.
     */
    protected function handle(string $id): Sandbox
    {
        return new Sandbox($this->name(), $id, new BoatDriver($this->name(), $id, $this->config), static::WORKDIR);
    }

    /**
     * Create a Boat sandbox, retrying a lost response without creating a second billable one.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function provision(array $options): array
    {
        $env = [...$this->config['env'] ?? [], ...$options['env'] ?? []];

        $response = $this->http()
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->retry(3, 1000, fn ($exception) => $exception instanceof ConnectionException, throw: false)
            ->post('sandboxes', array_filter([
                'type' => $this->config['type'] ?? 'small',
                'ttlSeconds' => $this->config['ttl'] ?? 900,
                'noEnv' => $this->config['no_env'] ?? true,
                'environment' => $this->config['environment'] ?? null,
                ...$options,
                'env' => $env,
            ], fn ($value) => $value !== null && $value !== []));

        return $this->ensureSuccessful($response)->json('sandbox');
    }

    /**
     * Get the Boat sandbox with the given ID, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    protected function find(string $id): ?array
    {
        $response = $this->http()->get("sandboxes/{$id}");

        return $response->notFound() ? null : $this->ensureSuccessful($response, $id)->json('sandbox');
    }

    /**
     * List the checkpoints taken of the sandbox with the given ID.
     *
     * @return array<int, string>
     */
    protected function checkpoints(string $id): array
    {
        return collect($this->ensureSuccessful($this->http()->get('named-snapshots'), $id)->json('snapshots', []))
            ->pluck('name')
            ->filter(fn ($name) => str_starts_with($name, $this->prefix($id)))
            ->values()
            ->all();
    }

    /**
     * Wait for the Boat sandbox with the given ID to reach the given state.
     *
     * @param  array<int, string>  $exactly
     * @return array<string, mixed>
     *
     * @throws SandboxException
     */
    protected function wait(string $id, SandboxState $state, array $exactly = []): array
    {
        $deadline = now()->addSeconds($this->config['start_timeout'] ?? 300);

        while (true) {
            $sandbox = $this->find($id) ?? throw SandboxNotFound::for($this->name(), $id);

            $current = BoatDriver::mapState($sandbox['state']);

            if ($current === $state && ($exactly === [] || in_array($sandbox['state'], $exactly, true))) {
                return $sandbox;
            }

            if (in_array($current, [SandboxState::Error, SandboxState::Terminated], true) || now()->isAfter($deadline)) {
                throw new SandboxException("Boat sandbox [{$id}] is {$sandbox['state']}: ".($sandbox['error'] ?? 'it did not become '.$state->value.' in time.'), $this->name(), $id);
            }

            Sleep::for(2)->seconds();
        }
    }

    /**
     * Wait for the named snapshot to finish saving.
     *
     * @throws SandboxException
     */
    protected function waitForSnapshot(string $id, string $name): void
    {
        $deadline = now()->addSeconds($this->config['snapshot_timeout'] ?? 600);

        while (true) {
            $response = $this->http()->get("named-snapshots/{$name}");

            if ($response->notFound()) {
                throw new SandboxException("Checkpoint [{$name}] of sandbox [{$id}] does not exist.", $this->name(), $id);
            }

            $snapshot = $this->ensureSuccessful($response, $id)->json('snapshot');

            if ($snapshot['status'] === 'ready') {
                return;
            }

            if ($snapshot['status'] === 'failed' || now()->isAfter($deadline)) {
                throw new SandboxException("Boat snapshot [{$name}] did not save: ".($snapshot['error'] ?? 'it timed out.'), $this->name(), $id);
            }

            Sleep::for(2)->seconds();
        }
    }

    /**
     * Fail when the Boat API returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response, ?string $id = null): Response
    {
        if ($response->failed()) {
            throw new SandboxException(
                "Boat request failed with status {$response->status()}: ".$response->json('message', $response->body()),
                $this->name(),
                $id,
            );
        }

        return $response;
    }

    /**
     * Get the prefix of the checkpoint names of the sandbox with the given ID, which Boat requires to be lowercase.
     */
    protected function prefix(string $id): string
    {
        return 'ai-'.substr($id, 3).'-';
    }

    /**
     * Determine whether the given ID is a Boat sandbox ID.
     */
    protected function valid(string $id): bool
    {
        return preg_match('/^bx_[23456789abcdefghjkmnpqrstuvwxyz]{8}$/', $id) === 1;
    }

    /**
     * Create an HTTP client for the Boat API.
     */
    protected function http(): PendingRequest
    {
        return BoatDriver::client($this->config);
    }
}
