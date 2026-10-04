<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Sandbox\Checkpointable;
use Laravel\Ai\Sandboxes\Drivers\LocalDriver;
use Laravel\Ai\Sandboxes\Exceptions\SandboxException;
use Laravel\Ai\Sandboxes\Exceptions\SandboxNotFound;

class LocalProvider extends Provider implements Checkpointable
{
    public function __construct(array $config = [], protected Filesystem $files = new Filesystem)
    {
        parent::__construct($config);
    }

    /**
     * {@inheritdoc}
     */
    protected function supportedOptions(): array
    {
        return ['env', 'network', 'isolate'];
    }

    /**
     * {@inheritdoc}
     */
    public function create(array $options = []): Sandbox
    {
        $options = $this->validate($options);

        $id = (string) Str::ulid();

        $this->files->ensureDirectoryExists($this->path($id));

        // Kept outside the workspace so commands in the sandbox cannot loosen their own options...
        $this->files->ensureDirectoryExists(dirname($this->optionsPath($id)));
        $this->files->put($this->optionsPath($id), json_encode($options, JSON_THROW_ON_ERROR));

        return $this->handle($id, $this->path($id), $options);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): Sandbox
    {
        if (str_starts_with($id, '/')) {
            $path = realpath($id);

            if ($path === false || ! is_dir($path)) {
                throw SandboxNotFound::for($this->name(), $id);
            }

            return $this->handle($path, $path);
        }

        if (! Str::isUlid($id) || ! $this->files->isDirectory($this->path($id))) {
            throw SandboxNotFound::for($this->name(), $id);
        }

        $options = $this->files->exists($this->optionsPath($id))
            ? json_decode($this->files->get($this->optionsPath($id)), true, flags: JSON_THROW_ON_ERROR)
            : [];

        return $this->handle($id, $this->path($id), $options);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $id): void
    {
        $this->ensureWorkspace($id);

        $this->files->deleteDirectory($this->path($id));
        $this->files->deleteDirectory($this->checkpointPath($id));
        $this->files->delete($this->optionsPath($id));
    }

    /**
     * {@inheritdoc}
     */
    public function checkpoint(string $id): string
    {
        $workspace = $this->get($this->ensureWorkspace($id))->root();

        $checkpoint = strtolower((string) Str::ulid());

        $this->files->ensureDirectoryExists($target = $this->checkpointPath($id, $checkpoint));

        $this->copy($workspace, $target);

        return $checkpoint;
    }

    /**
     * {@inheritdoc}
     */
    public function restore(string $id, string $checkpoint): Sandbox
    {
        $this->ensureWorkspace($id);

        if (! preg_match('/^[a-z0-9]{26}$/', $checkpoint) || ! $this->files->isDirectory($source = $this->checkpointPath($id, $checkpoint))) {
            throw new SandboxException("Checkpoint [{$checkpoint}] of sandbox [{$id}] does not exist.", $this->name(), $id);
        }

        $this->files->deleteDirectory($this->path($id));
        $this->files->ensureDirectoryExists($this->path($id));

        $this->copy($source, $this->path($id));

        return $this->get($id);
    }

    /**
     * {@inheritdoc}
     */
    public function forgetCheckpoint(string $id, string $checkpoint): void
    {
        $this->ensureWorkspace($id);

        if (preg_match('/^[a-z0-9]{26}$/', $checkpoint)) {
            $this->files->deleteDirectory($this->checkpointPath($id, $checkpoint));
        }
    }

    /**
     * Create a handle for the given workspace with the given options over the configured defaults.
     *
     * @param  array<string, mixed>  $options
     */
    protected function handle(string $id, string $path, array $options = []): Sandbox
    {
        $config = [
            ...$this->config,
            ...$options,
            'env' => [...$this->config['env'] ?? [], ...$options['env'] ?? []],
        ];

        return new Sandbox($this->name(), $id, new LocalDriver($this->name(), $id, $path, $config, $this->files), $path);
    }

    /**
     * Ensure the given ID names a workspace the provider created, never a directory it was attached to by path.
     *
     * @throws SandboxException
     */
    protected function ensureWorkspace(string $id): string
    {
        if (! Str::isUlid($id)) {
            throw new SandboxException("Only workspaces created by the [{$this->name()}] provider can be deleted or checkpointed, not [{$id}].", $this->name(), $id);
        }

        return $id;
    }

    /**
     * Copy a directory's contents, keeping symlinks as links rather than following them out of the workspace.
     *
     * @throws SandboxException
     */
    protected function copy(string $from, string $to): void
    {
        $result = Process::run(['cp', '-a', rtrim($from, '/').'/.', $to]);

        if (! $result->successful()) {
            throw new SandboxException('Sandbox checkpoint failed: '.trim($result->errorOutput()), $this->name());
        }
    }

    /**
     * Get the directory the workspaces live in.
     */
    protected function root(): string
    {
        return rtrim($this->config['root'] ?? storage_path('app/sandboxes'), '/');
    }

    /**
     * Get the workspace directory of the sandbox with the given ID.
     */
    protected function path(string $id): string
    {
        return $this->root().'/'.$id;
    }

    /**
     * Get the file the create options of the sandbox with the given ID are kept in.
     */
    protected function optionsPath(string $id): string
    {
        return $this->root().'/.options/'.$id.'.json';
    }

    /**
     * Get the directory the checkpoints of the sandbox with the given ID are kept in.
     */
    protected function checkpointPath(string $id, ?string $checkpoint = null): string
    {
        return $this->root().'/.checkpoints/'.$id.($checkpoint ? '/'.$checkpoint : '');
    }
}
