<?php

namespace Laravel\Ai\Providers;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;

class MistralProvider extends Provider implements TextProvider, TranscriptionProvider
{
    use Concerns\GeneratesText;
    use Concerns\GeneratesTranscriptions;
    use Concerns\HasTextGateway;
    use Concerns\HasTranscriptionGateway;
    use Concerns\StreamsText;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'mistral-medium-latest';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return 'mistral-small-latest';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return 'mistral-large-latest';
    }

    /**
     * Get the name of the default transcription (STT) model.
     */
    public function defaultTranscriptionModel(): string
    {
        return 'voxtral-small-latest';
    }
}
