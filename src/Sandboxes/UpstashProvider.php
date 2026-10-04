<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Sandbox\Checkpointable;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Sandboxes\Concerns\Polls;
use Laravel\Ai\Sandboxes\Drivers\UpstashDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;

class UpstashProvider extends Provider implements Checkpointable, Suspendable
{
    use Polls;

    /**
     * The directory Upstash boxes work in.
     */
    public const WORKDIR = '/workspace/home';

    /**
     * {@inheritdoc}
     */
    protected function supportedOptions(): array
    {
        return ['image', 'env', 'network', 'type'];
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $options = []): Sandbox
    {
        $options = $this->validate($options);

        $box = $this->ensureSuccessful($this->http()->post('v2/box', $this->payload($options)))->json();

        return $this->handle($this->waitUntilCreated($box['id'])['id']);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): Sandbox
    {
        $box = $this->valid($id) ? $this->find($id) : null;

        if ($box === null || $box['status'] === 'deleted') {
            throw SandboxNotFound::for($this->name(), $id);
        }

        return $this->handle($id);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $id): void
    {
        if (! $this->valid($id) || $this->find($id) === null) {
            return;
        }

        if ($snapshots = $this->snapshots($id)->pluck('id')->all()) {
            $this->ensureSuccessful($this->http()->delete('v2/box/snapshots', ['ids' => $snapshots]), $id);
        }

        $this->destroy($id);
    }

    /**
     * {@inheritdoc}
     */
    public function suspend(string $id): void
    {
        $this->get($id);

        $this->ensureSuccessful($this->http()->post("v2/box/{$id}/pause"), $id);
    }

    /**
     * {@inheritdoc}
     */
    public function resume(string $id): void
    {
        if (($state = $this->get($id)->state()) !== SandboxState::Stopped) {
            throw SandboxStateException::for($this->name(), $id, $state, SandboxState::Stopped);
        }

        $this->ensureSuccessful($this->http()->post("v2/box/{$id}/resume"), $id);
    }

    /**
     * {@inheritdoc}
     */
    public function checkpoint(string $id): string
    {
        $this->get($id);

        $snapshot = $this->ensureSuccessful($this->http()->post("v2/box/{$id}/snapshots", ['name' => 'laravel-ai-'.now()->format('YmdHisv')]), $id)->json();

        $this->poll($this->config['snapshot_timeout'] ?? 600, function () use ($id, $snapshot) {
            $status = $this->snapshots($id)->firstWhere('id', $snapshot['id'])['status'] ?? 'error';

            if ($status === 'error' || $status === 'deleted') {
                throw new SandboxException("Upstash snapshot [{$snapshot['id']}] did not save.", $this->name(), $id);
            }

            return $status === 'ready' ? true : null;
        }, "Upstash snapshot [{$snapshot['id']}] did not save in time.", $id);

        return $snapshot['id'];
    }

    /**
     * {@inheritdoc}
     */
    public function restore(string $id, string $checkpoint): Sandbox
    {
        $this->get($id);

        // Upstash restores into a new box, so the restored sandbox has its own ID...
        $box = $this->ensureSuccessful($this->http()->post('v2/box/from-snapshot', ['snapshot_id' => $checkpoint, ...$this->payload([])]), $id)->json();

        $restored = $this->waitUntilCreated($box['id']);

        $this->destroy($id);

        return $this->handle($restored['id']);
    }

    /**
     * {@inheritdoc}
     */
    public function forgetCheckpoint(string $id, string $checkpoint): void
    {
        $this->ensureSuccessful($this->http()->delete('v2/box/snapshots', ['ids' => [$checkpoint]]), $id);
    }

    /**
     * Get the create payload for the given options over the configured defaults.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function payload(array $options): array
    {
        $network = $options['network'] ?? $this->config['network'] ?? true;

        return array_filter([
            'runtime' => $options['image'] ?? $this->config['runtime'] ?? null,
            'size' => $options['type'] ?? $this->config['size'] ?? null,
            'env_vars' => [...$this->config['env'] ?? [], ...$options['env'] ?? []] ?: null,
            'network_policy' => ['mode' => $network ? 'allow-all' : 'deny-all'],
        ], fn ($value) => $value !== null);
    }

    /**
     * Wait for the box with the given ID to finish being created.
     *
     * @return array<string, mixed>
     *
     * @throws SandboxException
     */
    protected function waitUntilCreated(string $id): array
    {
        return $this->poll($this->config['start_timeout'] ?? 300, function () use ($id) {
            $box = $this->find($id) ?? throw SandboxNotFound::for($this->name(), $id);

            if ($box['status'] === 'error') {
                throw new SandboxException("Upstash box [{$id}] failed to start.", $this->name(), $id);
            }

            return $box['status'] === 'creating' ? null : $box;
        }, "Upstash box [{$id}] did not start in time.", $id);
    }

    /**
     * Get the box with the given ID, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    protected function find(string $id): ?array
    {
        $response = $this->http()->get("v2/box/{$id}");

        return $response->notFound() ? null : $this->ensureSuccessful($response, $id)->json();
    }

    /**
     * List the snapshots of the box with the given ID.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function snapshots(string $id)
    {
        return collect($this->ensureSuccessful($this->http()->get("v2/box/{$id}/snapshots"), $id)->json('snapshots', []));
    }

    /**
     * Delete the box with the given ID, succeeding when it is already gone.
     */
    protected function destroy(string $id): void
    {
        $response = $this->http()->delete("v2/box/{$id}");

        if (! $response->notFound()) {
            $this->ensureSuccessful($response, $id);
        }
    }

    /**
     * Create a handle for the box with the given ID.
     */
    protected function handle(string $id): Sandbox
    {
        return new Sandbox($this->name(), $id, new UpstashDriver($this->name(), $id, $this->config), static::WORKDIR);
    }

    /**
     * Determine whether the given ID could name a box.
     */
    protected function valid(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id) === 1;
    }

    /**
     * Fail when the Upstash API returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response, ?string $id = null): Response
    {
        if ($response->failed()) {
            throw new SandboxException("Upstash request failed with status {$response->status()}: ".$response->json('error', $response->body()), $this->name(), $id);
        }

        return $response;
    }

    /**
     * Create an HTTP client for the Upstash Box API.
     */
    protected function http(): PendingRequest
    {
        return UpstashDriver::client($this->config);
    }
}
