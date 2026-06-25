<?php

namespace Laravel\Ai\Gateway\OpenAiCompatible;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Gateway\Concerns\DelegatesToTextGenerationLoop;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Providers\Provider;

class OpenAiCompatibleGateway implements StepTextGateway, TextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesOpenAiCompatibleClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsChatCompletionMessages;
    use Concerns\MapsChatCompletionTools;
    use Concerns\ParsesTextResponses;
    use Concerns\PerformsChatCompletionSteps;
    use DelegatesToTextGenerationLoop;
    use HandlesFailoverErrors;
    use ParsesServerSentEvents;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * Get the stream options sent with a streaming Chat Completions request.
     */
    protected function streamOptions(Provider $provider): ?array
    {
        return $provider->additionalConfiguration()['stream_options'] ?? null;
    }
}
