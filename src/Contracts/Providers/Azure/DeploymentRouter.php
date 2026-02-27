<?php

namespace Laravel\Ai\Contracts\Providers\Azure;

interface DeploymentRouter
{
    /**
     * Map the given model to an Azure OpenAI deployment name.
     */
    public function route(string $model): string;
}
