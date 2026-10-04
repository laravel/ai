<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Sandboxes\Concerns\Polls;
use Laravel\Ai\Sandboxes\Drivers\DaytonaDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;

class DaytonaProvider extends Provider implements Suspendable
{
    use Polls;

    /**
     * The directory Daytona's default user works in.
     */
    public const WORKDIR = '/home/daytona';

    /**
     * {@inheritdoc}
     */
    protected function supportedOptions(): array
    {
        return ['image', 'env', 'cpus', 'memory', 'ttl', 'network'];
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $options = []): Sandbox
    {
        $options = $this->validate($options);

        $image = $options['image'] ?? $this->config['image'] ?? null;
        $ttl = $options['ttl'] ?? $this->config['ttl'] ?? null;
        $network = $options['network'] ?? $this->config['network'] ?? null;

        // Daytona builds a raw image from a Dockerfile; a configured snapshot starts without a build...
        $sandbox = $this->ensureSuccessful($this->http()->post('sandbox', array_filter([
            'snapshot' => $image === null ? $this->config['snapshot'] ?? null : null,
            'buildInfo' => $image === null ? null : ['dockerfileContent' => "FROM {$image}\n"],
            'env' => [...$this->config['env'] ?? [], ...$options['env'] ?? []] ?: null,
            'cpu' => $options['cpus'] ?? $this->config['cpus'] ?? null,
            'memory' => $options['memory'] ?? $this->config['memory'] ?? null,
            'autoStopInterval' => $ttl === null ? null : (int) ceil($ttl / 60),
            'networkBlockAll' => $network === null ? null : ! $network,
        ], fn ($value) => $value !== null)))->json();

        return $this->handle($this->waitUntilStarted($sandbox['id']));
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): Sandbox
    {
        $sandbox = $this->find($id);

        if ($sandbox === null || DaytonaDriver::mapState($sandbox['state'] ?? null) === SandboxState::Terminated) {
            throw SandboxNotFound::for($this->name(), $id);
        }

        return $this->handle($sandbox);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $id): void
    {
        if (! $this->valid($id)) {
            return;
        }

        $response = $this->http()->delete("sandbox/{$id}");

        // A 409 means the sandbox is already changing state, which includes being destroyed...
        if (! $response->notFound() && $response->status() !== 409) {
            $this->ensureSuccessful($response, $id);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function suspend(string $id): void
    {
        $this->get($id);

        $this->ensureSuccessful($this->http()->post("sandbox/{$id}/stop"), $id);
    }

    /**
     * {@inheritdoc}
     */
    public function resume(string $id): void
    {
        if (($state = $this->get($id)->state()) !== SandboxState::Stopped) {
            throw SandboxStateException::for($this->name(), $id, $state, SandboxState::Stopped);
        }

        $this->ensureSuccessful($this->http()->post("sandbox/{$id}/start"), $id);

        $this->waitUntilStarted($id);
    }

    /**
     * Wait for the sandbox with the given ID to start.
     *
     * @return array<string, mixed>
     *
     * @throws SandboxException
     */
    protected function waitUntilStarted(string $id): array
    {
        return $this->poll($this->config['start_timeout'] ?? 300, function () use ($id) {
            $sandbox = $this->find($id) ?? throw SandboxNotFound::for($this->name(), $id);

            if (in_array($sandbox['state'], ['error', 'build_failed'], true)) {
                throw new SandboxException("Daytona sandbox [{$id}] failed: ".($sandbox['errorReason'] ?? $sandbox['state']), $this->name(), $id);
            }

            return $sandbox['state'] === 'started' ? $sandbox : null;
        }, "Daytona sandbox [{$id}] did not start in time.", $id);
    }

    /**
     * Get the sandbox with the given ID, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    protected function find(string $id): ?array
    {
        if (! $this->valid($id)) {
            return null;
        }

        $response = $this->http()->get("sandbox/{$id}");

        return $response->notFound() ? null : $this->ensureSuccessful($response, $id)->json();
    }

    /**
     * Create a handle for the given sandbox.
     *
     * @param  array<string, mixed>  $sandbox
     */
    protected function handle(array $sandbox): Sandbox
    {
        $driver = new DaytonaDriver($this->name(), $sandbox['id'], $sandbox['toolboxProxyUrl'], $this->config);

        return new Sandbox($this->name(), $sandbox['id'], $driver, $this->config['workdir'] ?? static::WORKDIR);
    }

    /**
     * Determine whether the given ID is a Daytona sandbox ID.
     */
    protected function valid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }

    /**
     * Fail when the Daytona API returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response, ?string $id = null): Response
    {
        if ($response->failed()) {
            throw new SandboxException("Daytona request failed with status {$response->status()}: ".$response->json('message', $response->body()), $this->name(), $id);
        }

        return $response;
    }

    /**
     * Create an HTTP client for the Daytona control API.
     */
    protected function http(): PendingRequest
    {
        return DaytonaDriver::client($this->config);
    }
}
