<?php

namespace Laravel\Ai\Gateway\OpenResponses;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\OpenAi\Concerns as OpenAi;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;

class OpenResponsesGateway implements StepTextGateway
{
    use Concerns\CreatesOpenResponsesClient;
    use HandlesFailoverErrors;
    use OpenAi\BuildsTextRequests;
    use OpenAi\HandlesTextGeneration;
    use OpenAi\HandlesTextSteps;
    use OpenAi\MapsAttachments;
    use OpenAi\MapsMessages;
    use OpenAi\MapsTools;
    use OpenAi\ParsesTextResponses;
    use ParsesServerSentEvents;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * Determine if the server should receive full stateless conversation history.
     *
     * Open Responses servers are not required to store responses, so full history is sent unless "store" is enabled.
     */
    protected function isStateless(Provider $provider): bool
    {
        return filter_var(
            $provider->additionalConfiguration()['store'] ?? false,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) !== true;
    }

    /**
     * Determine if the model supports encrypted reasoning content.
     */
    protected function isReasoningModel(string $model): bool
    {
        return false;
    }

    /**
     * Resolve the provider options the agent and prompt set for this provider.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveProviderOptions(?TextGenerationOptions $options, Provider $provider): ?array
    {
        return $options?->providerOptions($provider->name());
    }

    /**
     * Validate the Open Responses response data.
     *
     * @throws AiException
     */
    protected function validateTextResponse(array $data): void
    {
        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'Open Responses Error: [%s] %s',
                $data['error']['code'] ?? $data['error']['type'] ?? 'unknown',
                $data['error']['message'] ?? 'Unknown Open Responses error.',
            ));
        }

        if (($data['status'] ?? '') === 'failed') {
            $error = $data['error'] ?? [];

            throw new AiException(sprintf(
                'Open Responses Error: [%s] %s',
                $error['code'] ?? $error['type'] ?? 'unknown',
                $error['message'] ?? 'The response failed without an error message.',
            ));
        }
    }
}
