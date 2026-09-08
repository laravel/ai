<?php

namespace Laravel\Ai\Concerns;

use Laravel\Ai\Contracts\Harness\Runtime;
use Laravel\Ai\Enums\Harness;
use Laravel\Ai\Harness\ClaudeCode\ClaudeCodeRuntime;
use Laravel\Ai\Harness\FakeRuntime;
use LogicException;

trait InteractsWithFakeHarnesses
{
    protected ?FakeRuntime $harnessFake = null;

    public function fakeHarness(array $responses = []): FakeRuntime
    {
        return $this->harnessFake = new FakeRuntime($responses);
    }

    public function harness(Harness|string $name = Harness::ClaudeCode): Runtime
    {
        if ($this->harnessFake !== null) {
            return $this->harnessFake;
        }

        $name = $name instanceof Harness ? $name->value : $name;
        $config = $this->app['config']->get('ai.harnesses.'.$name);

        if (! is_array($config)) {
            throw new LogicException("Harness [{$name}] is not configured.");
        }

        return match ($config['driver'] ?? null) {
            'claude-code' => new ClaudeCodeRuntime($config),
            default => throw new LogicException("Unsupported harness driver for [{$name}]."),
        };
    }
}
