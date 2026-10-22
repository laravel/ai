<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Support\MultipleInstanceManager;
use Laravel\Ai\Contracts\Sandbox\SandboxProvider;

/**
 * @method SandboxProvider instance(?string $name = null)
 */
class SandboxManager extends MultipleInstanceManager
{
    protected ?FakeProvider $fake = null;

    /**
     * Get a sandbox provider by name.
     */
    public function provider(?string $name = null): SandboxProvider
    {
        return $this->fake ?? $this->instance($name);
    }

    /**
     * Create a sandbox on the default provider.
     *
     * @param  array<string, mixed>  $options
     */
    public function create(array $options = []): Sandbox
    {
        return $this->provider()->create($options);
    }

    /**
     * Delete a sandbox on the default provider.
     */
    public function delete(string $id): void
    {
        $this->provider()->delete($id);
    }

    /**
     * Replace every sandbox provider with an in-memory fake whose new sandboxes start with the given files.
     *
     * @param  array<string, string>  $files
     */
    public function fake(array $files = []): FakeProvider
    {
        return $this->fake = new FakeProvider($files);
    }

    /**
     * Create the local sandbox provider.
     */
    protected function createLocalDriver(array $config): LocalProvider
    {
        return new LocalProvider($config);
    }

    /**
     * Create the Docker sandbox provider.
     */
    protected function createDockerDriver(array $config): DockerProvider
    {
        return new DockerProvider($config);
    }

    /**
     * Create the Boat sandbox provider.
     */
    protected function createBoatDriver(array $config): BoatProvider
    {
        return new BoatProvider($config);
    }

    /**
     * Create the BoxLite sandbox provider.
     */
    protected function createBoxliteDriver(array $config): BoxLiteProvider
    {
        return new BoxLiteProvider($config);
    }

    /**
     * Forward calls to the default provider, which is also how Sandbox::get() arrives since the parent reserves get().
     *
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->provider()->$method(...$parameters);
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultInstance()
    {
        return $this->config->get('ai.default_sandbox', 'local');
    }

    /**
     * {@inheritdoc}
     */
    public function setDefaultInstance($name)
    {
        $this->config->set('ai.default_sandbox', $name);
    }

    /**
     * {@inheritdoc}
     */
    public function getInstanceConfig($name)
    {
        $config = $this->config->get("ai.sandboxes.{$name}");

        return is_array($config) ? ['name' => $name, ...$config] : null;
    }
}
