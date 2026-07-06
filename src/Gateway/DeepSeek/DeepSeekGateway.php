<?php

namespace Laravel\Ai\Gateway\DeepSeek;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Gateway\Concerns\DelegatesToTextGenerationLoop;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Laravel\Ai\Gateway\OpenAiCompatible\Concerns\PerformsChatCompletionSteps;

class DeepSeekGateway implements StepTextGateway, TextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesDeepSeekClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\ParsesTextResponses;
    use DelegatesToTextGenerationLoop;
    use HandlesFailoverErrors;
    use MapsChatCompletionTools;
    use ParsesServerSentEvents;
    use PerformsChatCompletionSteps;

    public function __construct(protected Dispatcher $events)
    {
        //
    }
}
