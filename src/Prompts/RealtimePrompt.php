<?php

namespace Laravel\Ai\Prompts;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\RealtimeProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\ToolNameResolver;

class RealtimePrompt
{
    public readonly Agent $agent;

    public readonly RealtimeProvider $provider;

    public readonly string $model;

    public readonly string $voice;

    public readonly array $modalities;

    public readonly ?string $instructions;

    public readonly array $tools;

    public readonly array $options;

    public readonly int $timeout;

    /**
     * Create a new realtime prompt instance.
     */
    public function __construct(
        Agent $agent,
        RealtimeProvider $provider,
        string $model,
        string $voice,
        array $modalities = ['text', 'audio'],
        ?string $instructions = null,
        array $tools = [],
        array $options = [],
        int $timeout = 30,
    ) {
        $this->agent = $agent;
        $this->provider = $provider;
        $this->model = $model;
        $this->voice = $voice;
        $this->modalities = $modalities;
        $this->instructions = $instructions ?? (string) $agent->instructions();
        $this->tools = $tools;
        $this->options = $options;
        $this->timeout = $timeout;
    }

    /**
     * Determine if the instructions contain the given string.
     */
    public function contains(string $string): bool
    {
        return Str::contains($this->instructions ?? '', $string);
    }

    /**
     * Determine if the prompt contains a specific tool.
     */
    public function hasTool(string|Tool $tool): bool
    {
        $target = is_string($tool) ? $tool : ToolNameResolver::resolve($tool);

        return (new Collection($this->tools))->contains(function ($t) use ($target): bool {
            if (is_string($t)) {
                return $t === $target;
            }

            if ($t instanceof Tool) {
                return ToolNameResolver::resolve($t) === $target || $t::class === $target;
            }

            return false;
        });
    }
}
