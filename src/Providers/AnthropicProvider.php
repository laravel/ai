<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Providers\TextProvider;

class AnthropicProvider extends Provider implements TextProvider
{
    use Concerns\GeneratesText;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'claude-haiku-4-5-20251001';
    }
}
