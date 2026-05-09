<?php

namespace Laravel\Ai\Gateway\Xai;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\SingleTurnResponse;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;

class XaiGateway implements TextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesXaiClient;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use HandlesFailoverErrors;
    use ParsesServerSentEvents;

    public function __construct(protected Dispatcher $events) {}

    /**
     * {@inheritdoc}
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?string $previousResponseId = null,
        ?StepContext $context = null,
    ): SingleTurnResponse {
        $body = $previousResponseId
            ? $this->buildContinuationBody($previousResponseId, $model, $messages, $tools, $provider, $schema, $options)
            : $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('responses', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, filled($schema));
    }

    /**
     * {@inheritdoc}
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
        ?string $previousResponseId = null,
        ?StepContext $context = null,
    ): Generator {
        $body = $previousResponseId
            ? $this->buildContinuationBody($previousResponseId, $model, $messages, $tools, $provider, $schema, $options)
            : $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $body['stream'] = true;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('responses', $body),
        );

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $response->getBody(),
        );
    }
}
