<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\Checkpointable;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Sandboxes\Drivers\BoxLiteDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;

class BoxLiteProvider extends Provider implements Checkpointable, Suspendable
{
    /**
     * {@inheritdoc}
     */
    protected function supportedOptions(): array
    {
        return ['image', 'env', 'workdir', 'cpus', 'memory', 'ttl'];
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $options = []): Sandbox
    {
        $options = $this->validate($options);

        $workdir = $options['workdir'] ?? $this->workdir();

        $box = $this->ensureSuccessful($this->http()->post('boxes', array_filter([
            'image' => $options['image'] ?? $this->config['image'] ?? 'alpine:latest',
            'cpus' => isset($options['cpus']) || isset($this->config['cpus']) ? (int) ($options['cpus'] ?? $this->config['cpus']) : null,
            'memory_mib' => isset($options['memory']) || isset($this->config['memory']) ? (int) ($options['memory'] ?? $this->config['memory']) : null,
            'env' => [...$this->config['env'] ?? [], ...$options['env'] ?? []] ?: null,
            'auto_stop' => $options['ttl'] ?? $this->config['ttl'] ?? null,
            'auto_resume' => true,
        ], fn ($value) => $value !== null)))->json();

        $this->ensureSuccessful($this->http()->post("boxes/{$box['box_id']}/start"), $box['box_id']);

        $sandbox = $this->handle($box['box_id'], $workdir);

        $sandbox->driver()->mkdir($workdir, recursive: true);

        return $sandbox;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): Sandbox
    {
        if (! $this->valid($id) || $this->http()->get("boxes/{$id}")->notFound()) {
            throw SandboxNotFound::for($this->name(), $id);
        }

        return $this->handle($id, $this->workdir());
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $id): void
    {
        if (! $this->valid($id)) {
            return;
        }

        $response = $this->http()->delete("boxes/{$id}?force=true");

        if (! $response->notFound()) {
            $this->ensureSuccessful($response, $id);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function suspend(string $id): void
    {
        $this->get($id);

        $this->ensureSuccessful($this->http()->post("boxes/{$id}/stop"), $id);
    }

    /**
     * {@inheritdoc}
     */
    public function resume(string $id): void
    {
        if (($state = $this->get($id)->state()) !== SandboxState::Stopped) {
            throw SandboxStateException::for($this->name(), $id, $state, SandboxState::Stopped);
        }

        $this->ensureSuccessful($this->http()->post("boxes/{$id}/start"), $id);
    }

    /**
     * {@inheritdoc}
     */
    public function checkpoint(string $id): string
    {
        $checkpoint = 'laravel-ai-'.strtolower((string) Str::ulid());

        $this->whileStopped($id, fn () => $this->ensureSuccessful($this->http()->post("boxes/{$id}/snapshots", ['name' => $checkpoint]), $id));

        return $checkpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function restore(string $id, string $checkpoint): Sandbox
    {
        $this->whileStopped($id, fn () => $this->ensureSuccessful($this->http()->post("boxes/{$id}/snapshots/{$checkpoint}/restore"), $id));

        return $this->get($id);
    }

    /**
     * {@inheritdoc}
     */
    public function forgetCheckpoint(string $id, string $checkpoint): void
    {
        $response = $this->http()->delete("boxes/{$id}/snapshots/{$checkpoint}");

        if (! $response->notFound()) {
            $this->ensureSuccessful($response, $id);
        }
    }

    /**
     * Run the callback with the box stopped, as BoxLite snapshots require, starting it again if it was running.
     *
     * @param  callable(): mixed  $callback
     */
    protected function whileStopped(string $id, callable $callback): void
    {
        $running = $this->get($id)->state() === SandboxState::Running;

        if ($running) {
            $this->ensureSuccessful($this->http()->post("boxes/{$id}/stop"), $id);
        }

        try {
            $callback();
        } finally {
            if ($running) {
                $this->ensureSuccessful($this->http()->post("boxes/{$id}/start"), $id);
            }
        }
    }

    /**
     * Create a handle for the box with the given ID.
     */
    protected function handle(string $id, string $workdir): Sandbox
    {
        return new Sandbox($this->name(), $id, new BoxLiteDriver($this->name(), $id, $this->config), $workdir);
    }

    /**
     * Get the directory sandboxes work in inside the box.
     */
    protected function workdir(): string
    {
        return $this->config['workdir'] ?? '/workspace';
    }

    /**
     * Determine whether the given ID could name a box.
     */
    protected function valid(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $id) === 1;
    }

    /**
     * Fail when the BoxLite server returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response, ?string $id = null): Response
    {
        if ($response->failed()) {
            throw new SandboxException("BoxLite request failed with status {$response->status()}: ".$response->json('error.message', $response->body()), $this->name(), $id);
        }

        return $response;
    }

    /**
     * Create an HTTP client for the BoxLite server.
     */
    protected function http(): PendingRequest
    {
        return BoxLiteDriver::client($this->config);
    }
}
