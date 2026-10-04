<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\Checkpointable;
use Laravel\Ai\Contracts\Sandbox\ForgetsSandboxes;
use Laravel\Ai\Contracts\Sandbox\SandboxFactory;
use Laravel\Ai\Sandboxes\Drivers\LocalDriver;
use RuntimeException;

class LocalFactory implements Checkpointable, ForgetsSandboxes, SandboxFactory
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
        $this->files->deleteDirectory($this->checkpointPath($id));
    }

    /**
     * {@inheritdoc}
     */
    public function checkpoint(string $id): string
    {
        return Sandbox::exclusively($id, function () use ($id): string {
            $checkpoint = strtolower((string) Str::ulid());

            $this->files->ensureDirectoryExists($target = $this->checkpointPath($id, $checkpoint));

            $this->copy($this->create($id)->cwd(), $target);

            return $checkpoint;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function restore(string $id, string $checkpoint): void
    {
        if (! $this->files->isDirectory($source = $this->checkpointPath($id, $checkpoint))) {
            throw new RuntimeException("Checkpoint [{$checkpoint}] of sandbox [{$id}] does not exist.");
        }

        Sandbox::exclusively($id, function () use ($id, $source): void {
            $this->files->deleteDirectory($this->path($id));
            $this->files->ensureDirectoryExists($this->path($id));

            $this->copy($source, $this->path($id));
        });
    }

    /**
     * {@inheritdoc}
     */
    public function forgetCheckpoint(string $id, string $checkpoint): void
    {
        $this->files->deleteDirectory($this->checkpointPath($id, $checkpoint));
    }

    /**
     * Copy a directory's contents, keeping symlinks as links rather than following them out of the workspace.
     */
    protected function copy(string $from, string $to): void
    {
        $result = Process::run(['cp', '-a', rtrim($from, '/').'/.', $to]);

        if (! $result->successful()) {
            throw new RuntimeException('Sandbox checkpoint failed: '.trim($result->errorOutput()));
        }
    }

    /**
     * Get the directory the checkpoints of the sandbox with the given ID are kept in, outside its workspace.
     */
    protected function checkpointPath(string $id, ?string $checkpoint = null): string
    {
        return rtrim($this->config['root'] ?? storage_path('app/sandboxes'), '/').'/.checkpoints/'.basename($id).($checkpoint ? '/'.basename($checkpoint) : '');
    }

    /**
     * Get the directory the sandbox with the given ID lives in.
     */
    protected function path(string $id): string
    {
        return rtrim($this->config['root'] ?? storage_path('app/sandboxes'), '/').'/'.basename($id);
    }
}
