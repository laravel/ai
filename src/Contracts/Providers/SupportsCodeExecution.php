<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Providers\Tools\CodeExecution;

interface SupportsCodeExecution
{
    /**
     * Get the code execution tool options for the provider.
     */
    public function codeExecutionToolOptions(CodeExecution $codeExecution): array;
}
