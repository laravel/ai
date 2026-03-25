<?php

namespace Laravel\Ai\Gateway\Xai;

use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Concerns\HandlesRateLimiting;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\TextResponse;

class XaiGateway implements TextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsAttachments;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use HandlesRateLimiting;
    use InvokesTools;
    use ParsesServerSentEvents;

    public function __construct(protected Dispatcher $events)
    {
        $this->initializeToolCallbacks();
    }

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
    ): TextResponse {
        $body = $this->buildTextRequestBody(
            $provider, $model, $instructions, $messages, $tools, $schema, $options,
        );

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('responses', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, filled($schema), $tools, $schema, $options);
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
    ): Generator {
        $body = $this->buildTextRequestBody(
            $provider, $model, $instructions, $messages, $tools, $schema, $options,
        );

        $body['stream'] = true;

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('responses', $body),
        );

        yield from $this->processTextStream(
            $invocationId, $provider, $model, $tools, $schema, $options,
            $response->getBody(),
        );
    }

    /**
     * Get an HTTP client for the xAI API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return Http::baseUrl('https://api.x.ai/v1')
            ->withToken($provider->providerCredentials()['key'])
            ->timeout($timeout ?? 60)
            ->throw();
    }
}
