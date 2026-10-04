<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Sandboxes\Drivers\E2bDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;

class E2bProvider extends Provider implements Suspendable
{
    /**
     * The directory E2B's default user works in.
     */
    public const WORKDIR = '/home/user';

    /**
     * {@inheritdoc}
     */
    protected function supportedOptions(): array
    {
        return ['image', 'env', 'ttl', 'network'];
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $options = []): Sandbox
    {
        $options = $this->validate($options);

        // Pausing on timeout instead of killing keeps the files, the way every other provider does...
        $sandbox = $this->ensureSuccessful($this->http()->post('v2/sandboxes', array_filter([
            'templateID' => $options['image'] ?? $this->config['template'] ?? 'base',
            'timeout' => $options['ttl'] ?? $this->config['ttl'] ?? 900,
            'envVars' => [...$this->config['env'] ?? [], ...$options['env'] ?? []] ?: null,
            'allow_internet_access' => $options['network'] ?? $this->config['network'] ?? null,
            'autoPause' => true,
            'autoResume' => ['enabled' => true],
        ], fn ($value) => $value !== null)))->json();

        return $this->handle($sandbox);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): Sandbox
    {
        return $this->handle($this->find($id) ?? throw SandboxNotFound::for($this->name(), $id));
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $id): void
    {
        if (! $this->valid($id)) {
            return;
        }

        $response = $this->http()->delete("sandboxes/{$id}");

        if (! $response->notFound()) {
            $this->ensureSuccessful($response, $id);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function suspend(string $id): void
    {
        $response = $this->http()->post("sandboxes/{$id}/pause");

        match (true) {
            $response->notFound() => throw SandboxNotFound::for($this->name(), $id),
            $response->status() === 409 => null,
            default => $this->ensureSuccessful($response, $id),
        };
    }

    /**
     * {@inheritdoc}
     */
    public function resume(string $id): void
    {
        if (($state = $this->get($id)->state()) !== SandboxState::Stopped) {
            throw SandboxStateException::for($this->name(), $id, $state, SandboxState::Stopped);
        }

        $this->ensureSuccessful($this->http()->post("v2/sandboxes/{$id}/connect", ['timeout' => $this->config['ttl'] ?? 900]), $id);
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

        $response = $this->http()->get("sandboxes/{$id}");

        return $response->notFound() ? null : $this->ensureSuccessful($response, $id)->json();
    }

    /**
     * Create a handle for the given sandbox.
     *
     * @param  array<string, mixed>  $sandbox
     */
    protected function handle(array $sandbox): Sandbox
    {
        $driver = new E2bDriver(
            $this->name(),
            $sandbox['sandboxID'],
            $sandbox['envdAccessToken'] ?? null,
            $sandbox['domain'] ?? $this->config['domain'] ?? 'e2b.app',
            $this->config,
        );

        return new Sandbox($this->name(), $sandbox['sandboxID'], $driver, static::WORKDIR);
    }

    /**
     * Determine whether the given ID could name an E2B sandbox.
     */
    protected function valid(string $id): bool
    {
        return preg_match('/^[a-z0-9]{1,64}$/', $id) === 1;
    }

    /**
     * Fail when the E2B API returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response, ?string $id = null): Response
    {
        if ($response->failed()) {
            throw new SandboxException("E2B request failed with status {$response->status()}: ".$response->json('message', $response->body()), $this->name(), $id);
        }

        return $response;
    }

    /**
     * Create an HTTP client for the E2B control plane.
     */
    protected function http(): PendingRequest
    {
        return E2bDriver::client($this->config);
    }
}
