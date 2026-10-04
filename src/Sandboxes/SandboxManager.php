<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Support\MultipleInstanceManager;
use Laravel\Ai\Contracts\Sandbox\SandboxFactory;

/**
 * @method SandboxFactory instance(?string $name = null)
 */
class SandboxManager extends MultipleInstanceManager
{
    protected ?FakeFactory $fake = null;

    /**
     * Get a sandbox factory by name.
     */
    public function factory(?string $name = null): SandboxFactory
    {
        return $this->fake ?? $this->instance($name);
    }

    /**
     * Replace every sandbox factory with an in-memory fake.
     *
     * @param  array<string, string>  $files
     */
    public function fake(array $files = []): FakeFactory
    {
        return $this->fake = new FakeFactory($files);
    }

    /**
     * Create the local sandbox factory.
     */
    protected function createLocalDriver(array $config): LocalFactory
    {
        return new LocalFactory($config);
    }

    /**
     * Create the Docker sandbox factory.
     */
    protected function createDockerDriver(array $config): DockerFactory
    {
        return new DockerFactory($config);
    }

    /**
     * Create the Boat sandbox factory.
     */
    protected function createBoatDriver(array $config): BoatFactory
    {
        return new BoatFactory($config);
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
        return $this->config->get("ai.sandboxes.{$name}");
    }
}
