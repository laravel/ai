<?php

namespace Laravel\Ai\Contracts;

interface HasTelemetryContext
{
    /**
     * Return agent-specific telemetry metadata to attach to spans.
     * Keys are merged with cross-cutting metadata from Laravel's Context facade.
     *
     * @return array<string, mixed>
     */
    public function telemetryContext(): array;
}
