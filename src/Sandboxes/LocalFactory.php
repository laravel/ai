<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\Contracts\Sandbox\ForgetsSandboxes;
use Laravel\Ai\Contracts\Sandbox\SandboxFactory;
use Laravel\Ai\Sandboxes\Drivers\LocalDriver;

class LocalFactory implements ForgetsSandboxes, SandboxFactory
{
    public function __construct(
        protected array $config,
        protected Filesystem $files = new Filesystem,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function create(string $id): Sandbox
    {
        $this->files->ensureDirectoryExists($path = $this->path($id));

        return Sandbox::fromDriver(new LocalDriver($this->config, $this->files, $path), $path);
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
    public function forget(string $id): void
    {
        $this->files->deleteDirectory($this->path($id));
    }

    /**
     * Get the directory the sandbox with the given ID lives in.
     */
    protected function path(string $id): string
    {
        return rtrim($this->config['root'] ?? storage_path('app/sandboxes'), '/').'/'.basename($id);
    }
}
