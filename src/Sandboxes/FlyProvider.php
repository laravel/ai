<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Contracts\Sandbox\Suspendable;
use Laravel\Ai\Sandboxes\Concerns\Polls;
use Laravel\Ai\Sandboxes\Drivers\FlyDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;
use Laravel\Ai\Sandboxes\Exceptions\SandboxStateException;
use Laravel\Ai\Sandboxes\Exceptions\UnsupportedOptionException;

class FlyProvider extends Provider implements Suspendable
{
    use Polls;

    /**
     * {@inheritdoc}
     */
    protected function supportedOptions(): array
    {
        return ['image', 'env', 'workdir', 'cpus', 'memory', 'network'];
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $options = []): Sandbox
    {
        $options = $this->validate($options);

        if (($options['network'] ?? true) === false) {
            throw UnsupportedOptionException::for($this->name(), ['network'], 'Fly machines always have network access.');
        }

        $workdir = $options['workdir'] ?? $this->workdir();

        // The machine idles on sleep and is never restarted by Fly, so a stopped sandbox stays stopped...
        $machine = $this->ensureSuccessful($this->http()->post('machines', array_filter([
            'region' => $this->config['region'] ?? null,
            'config' => [
                'image' => $options['image'] ?? $this->config['image'] ?? 'ubuntu:24.04',
                'env' => (object) [...$this->config['env'] ?? [], ...$options['env'] ?? []],
                'guest' => [
                    'cpu_kind' => $this->config['cpu_kind'] ?? 'shared',
                    'cpus' => (int) ($options['cpus'] ?? $this->config['cpus'] ?? 1),
                    'memory_mb' => (int) ($options['memory'] ?? $this->config['memory'] ?? 1024),
                ],
                'init' => ['cmd' => ['sleep', 'infinity']],
                'restart' => ['policy' => 'no'],
            ],
        ])))->json();

        $this->waitFor($machine['id'], 'started');

        $sandbox = $this->handle($machine['id'], $workdir);

        $sandbox->driver()->mkdir($workdir, recursive: true);

        return $sandbox;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): Sandbox
    {
        $machine = $this->find($id);

        if ($machine === null || FlyDriver::mapState($machine['state'] ?? null) === SandboxState::Terminated) {
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

        $response = $this->http()->delete("machines/{$id}?force=true");

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

        $this->ensureSuccessful($this->http()->post("machines/{$id}/suspend"), $id);
    }

    /**
     * {@inheritdoc}
     */
    public function resume(string $id): void
    {
        if (($state = $this->get($id)->state()) !== SandboxState::Stopped) {
            throw SandboxStateException::for($this->name(), $id, $state, SandboxState::Stopped);
        }

        // Starting a suspended machine resumes it, or boots it cold when its memory snapshot was lost...
        $this->ensureSuccessful($this->http()->post("machines/{$id}/start"), $id);

        $this->waitFor($id, 'started');
    }

    /**
     * Wait for the machine with the given ID to reach the given state, through the API's blocking wait.
     *
     * @throws SandboxException
     */
    protected function waitFor(string $id, string $state): void
    {
        $this->poll($this->config['start_timeout'] ?? 300, function () use ($id, $state) {
            $response = $this->http()->timeout(90)->get("machines/{$id}/wait", ['state' => $state, 'timeout' => 60]);

            return $response->successful() ? true : null;
        }, "Fly machine [{$id}] did not reach {$state} in time.", $id);
    }

    /**
     * Get the machine with the given ID, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    protected function find(string $id): ?array
    {
        if (! $this->valid($id)) {
            return null;
        }

        $response = $this->http()->get("machines/{$id}");

        return $response->notFound() ? null : $this->ensureSuccessful($response, $id)->json();
    }

    /**
     * Create a handle for the machine with the given ID.
     */
    protected function handle(string $id, string $workdir): Sandbox
    {
        return new Sandbox($this->name(), $id, new FlyDriver($this->name(), $id, $this->config), $workdir);
    }

    /**
     * Get the directory sandboxes work in on the machine.
     */
    protected function workdir(): string
    {
        return $this->config['workdir'] ?? '/workspace';
    }

    /**
     * Determine whether the given ID is a Fly machine ID.
     */
    protected function valid(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8,32}$/', $id) === 1;
    }

    /**
     * Fail when the Machines API returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response, ?string $id = null): Response
    {
        if ($response->failed()) {
            throw new SandboxException("Fly request failed with status {$response->status()}: ".$response->json('error', $response->body()), $this->name(), $id);
        }

        return $response;
    }

    /**
     * Create an HTTP client for the app's machines.
     */
    protected function http(): PendingRequest
    {
        return FlyDriver::client($this->config);
    }
}
