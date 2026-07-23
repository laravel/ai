<?php

namespace Laravel\Ai\Responses\Concerns;

use Illuminate\Http\Client\Response as HttpResponse;

trait HasRawResponse
{
    /**
     * The raw HTTP response, if available for the provider.
     */
    public ?HttpResponse $raw = null;

    /**
     * Set the raw HTTP response.
     */
    public function withRaw(?HttpResponse $response): self
    {
        $this->raw = $response;

        return $this;
    }

    /**
     * Prepare the instance for serialization, discarding the unserializable raw HTTP response.
     */
    public function __serialize(): array
    {
        $vars = get_mangled_object_vars($this);

        unset($vars['raw']);

        return $vars;
    }
}
