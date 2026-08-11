<?php

namespace Laravel\Ai\Gateway\DeepSeek;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Gateway\Concerns\CreatesClient;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\PerformsChatCompletionSteps;

class DeepSeekGateway implements StepTextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\ParsesTextResponses;
    use CreatesClient;
    use HandlesFailoverErrors;
    use MapsChatCompletionTools;
    use ParsesServerSentEvents;
    use PerformsChatCompletionSteps;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * Get the API URL used when the provider has no configured URL.
     */
    protected function defaultBaseUrl(): string
    {
        return 'https://api.deepseek.com/v1';
    }
}
