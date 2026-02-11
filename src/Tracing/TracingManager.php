<?php

namespace Laravel\Ai\Tracing;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Laravel\Ai\Contracts\TracingDriver;
use Laravel\Ai\Tracing\Drivers\LangfuseDriver;
use Laravel\Ai\Tracing\Drivers\LogDriver;
use Laravel\Ai\Tracing\Drivers\NullDriver;

class TracingManager
{
    /**
     * The resolved driver instances.
     *
     * @var array<string, TracingDriver>
     */
    protected array $drivers = [];

    /**
     * Create a new tracing manager instance.
     */
    public function __construct(protected Application $app) {}

    /**
     * Get a tracing driver instance by name.
     */
    public function driver(?string $name = null): TracingDriver
    {
        $name = $name ?? $this->getDefaultDriver();

        return $this->drivers[$name] ??= $this->resolve($name);
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->app['config']['ai.tracing.default'] ?? 'log';
    }

    /**
     * Resolve the given driver.
     */
    protected function resolve(string $name): TracingDriver
    {
        $config = $this->app['config']["ai.tracing.drivers.{$name}"] ?? null;

        if (is_null($config)) {
            throw new InvalidArgumentException("Tracing driver [{$name}] is not defined.");
        }

        return match ($config['driver'] ?? $name) {
            'log' => new LogDriver($config),
            'langfuse' => new LangfuseDriver($config),
            'null' => new NullDriver,
            default => throw new InvalidArgumentException("Unsupported tracing driver [{$config['driver']}]."),
        };
    }
}
