<?php

namespace Laravel\Ai\Facades;

use Illuminate\Support\Facades\Facade;
use Laravel\Ai\Sandboxes\SandboxManager;

/**
 * @method static \Laravel\Ai\Contracts\Sandbox\SandboxProvider provider(?string $name = null)
 * @method static \Laravel\Ai\Sandboxes\Sandbox create(array $options = [])
 * @method static \Laravel\Ai\Sandboxes\Sandbox get(string $id)
 * @method static void delete(string $id)
 * @method static \Laravel\Ai\Sandboxes\FakeProvider fake(array $files = [])
 * @method static \Laravel\Ai\Sandboxes\SandboxManager extend(string $name, \Closure $callback)
 * @method static string getDefaultInstance()
 * @method static void setDefaultInstance(string $name)
 *
 * @see SandboxManager
 */
class Sandbox extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return SandboxManager::class;
    }
}
