<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Http\Client\Response;
use Laravel\Ai\Sandboxes\Drivers\CloudflareDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;

class CloudflareProvider extends Provider
{
    /**
     * The directory the bridge confines sandboxes to.
     */
    public const WORKDIR = '/workspace';

    /**
     * {@inheritdoc}
     */
    protected function supportedOptions(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $options = []): Sandbox
    {
        $this->validate($options);

        $id = $this->ensureSuccessful(CloudflareDriver::client($this->config)->post('sandbox'))->json('id');

        return $this->handle($id);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): Sandbox
    {
        // The bridge gives any valid ID a container on first use, so attaching can only check the ID's shape...
        if (preg_match('/^[a-z2-7]{1,128}$/', $id) !== 1) {
            throw SandboxNotFound::for($this->name(), $id);
        }

        return $this->handle($id);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $id): void
    {
        if (preg_match('/^[a-z2-7]{1,128}$/', $id) === 1) {
            $this->ensureSuccessful(CloudflareDriver::client($this->config)->delete("sandbox/{$id}"), $id);
        }
    }

    /**
     * Create a handle for the sandbox with the given ID.
     */
    protected function handle(string $id): Sandbox
    {
        return new Sandbox($this->name(), $id, new CloudflareDriver($this->name(), $id, $this->config), static::WORKDIR);
    }

    /**
     * Fail when the bridge returned an error.
     *
     * @throws SandboxException
     */
    protected function ensureSuccessful(Response $response, ?string $id = null): Response
    {
        if ($response->failed()) {
            throw new SandboxException("Cloudflare bridge request failed with status {$response->status()}: ".$response->json('error', $response->body()), $this->name(), $id);
        }

        return $response;
    }
}
