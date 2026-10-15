<?php

namespace Laravel\Ai\Sandboxes\Exceptions;

class UnsupportedOptionException extends SandboxException
{
    /**
     * Create an exception for create options the provider cannot honor.
     *
     * @param  array<int, string>  $options
     */
    public static function for(string $provider, array $options, string $reason = ''): self
    {
        $options = implode(', ', $options);

        return new self(trim("Sandbox provider [{$provider}] does not support [{$options}]. {$reason}"), $provider);
    }
}
