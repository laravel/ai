<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Gateway\FileGateway;
use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\AnthropicFileGateway;

class AnthropicProvider extends Provider implements FileProvider, TextProvider
{
    use Concerns\GeneratesText;
    use Concerns\HasFileGateway;
    use Concerns\HasTextGateway;
    use Concerns\ManagesFiles;
    use Concerns\StreamsText;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'claude-sonnet-4-5-20250929';
    }

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway ??= new AnthropicFileGateway;
    }
}
