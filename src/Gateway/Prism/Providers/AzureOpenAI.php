<?php

namespace Laravel\Ai\Gateway\Prism\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\OpenAI\OpenAI;

class AzureOpenAI extends OpenAI
{
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        string $url,
        public readonly ?string $apiVersion,
    ) {
        parent::__construct(
            apiKey: $apiKey,
            url: $url,
            organization: null,
            project: null,
        );
    }

    #[\Override]
    public function handleRequestException(string $model, RequestException $e): never
    {
        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($e->response),
                retryAfter: (int) $e->response->header('retry-after')
            ),
            529 => throw PrismProviderOverloadedException::make('Azure OpenAI'),
            413 => throw PrismRequestTooLargeException::make('Azure OpenAI'),
            default => $this->handleResponseErrors($e),
        };
    }

    #[\Override]
    protected function handleResponseErrors(RequestException $e): never
    {
        $data = $e->response->json() ?? [];
        $message = data_get($data, 'error.message');
        $message = is_array($message) ? implode(', ', $message) : $message;

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'Azure OpenAI',
            statusCode: $e->response->getStatusCode(),
            errorType: data_get($data, 'error.type'),
            errorMessage: $message,
            previous: $e
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    #[\Override]
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        return $this->baseClient()
            ->withHeaders([
                'api-key' => $this->apiKey,
            ])
            ->when($this->apiVersion, fn ($client) => $client->withQueryParameters([
                'api-version' => $this->apiVersion,
            ]))
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }
}
