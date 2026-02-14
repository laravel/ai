<?php

namespace Laravel\Ai\Providers\Azure;

use Laravel\Ai\Contracts\Providers\Azure\DeploymentRouter;

class MapDeploymentRouter implements DeploymentRouter
{
    /**
     * Create a new map deployment router instance.
     */
    public function __construct(protected array $map) {}

    /**
     * {@inheritdoc}
     */
    public function route(string $model): string
    {
        return $this->map[$model] ?? $model;
    }
}
