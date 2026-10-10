<?php

namespace Laravel\Ai\Sandboxes;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Sandbox\SandboxProvider;
use Laravel\Ai\Sandboxes\Exceptions\UnsupportedOptionException;

abstract class Provider implements SandboxProvider
{
    public function __construct(protected array $config = []) {}

    /**
     * Get the create options the provider honors.
     *
     * @return array<int, string>
     */
    abstract protected function supportedOptions(): array;

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return $this->config['name'] ?? $this->config['driver'];
    }

    /**
     * {@inheritdoc}
     */
    public function lock(string $id, int $seconds): Lock
    {
        return Cache::lock("ai:sandbox:{$this->name()}:{$id}", $seconds);
    }

    /**
     * Validate the given create options before anything is provisioned.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws UnsupportedOptionException
     */
    protected function validate(array $options): array
    {
        if ($unsupported = array_diff(array_keys($options), $this->supportedOptions())) {
            throw UnsupportedOptionException::for($this->name(), array_values($unsupported));
        }

        if (array_key_exists('network', $options) && ! is_bool($options['network'])) {
            throw UnsupportedOptionException::for($this->name(), ['network'], 'Network allowlists are not enforced by any provider yet; pass true or false.');
        }

        foreach (array_keys($options['env'] ?? []) as $name) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name)) {
                throw new InvalidArgumentException("Invalid environment variable name [{$name}].");
            }
        }

        return $options;
    }
}
