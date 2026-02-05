<?php

namespace Laravel\Ai\Gateway\Prism;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\ModerationGateway;
use Laravel\Ai\Contracts\Providers\ModerationProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\ModerationResponse;
use Laravel\Ai\Responses\ModerationResult;

class PrismModerationGateway implements ModerationGateway
{
    public function __construct(protected Dispatcher $dispatcher) {}

    /**
     * Moderate the given input(s).
     */
    public function moderate(
        ModerationProvider $provider,
        string $model,
        string|array $input
    ): ModerationResponse {
        $httpResponse = $this->buildHttpClient($provider)->post('/moderations', [
            'model' => $model,
            'input' => $input,
        ]);

        $responseData = $httpResponse->json();
        
        $parsedResults = collect($responseData['results'] ?? [])
            ->map(fn (array $item) => $this->parseModerationResult($item))
            ->all();

        return new ModerationResponse(
            $parsedResults,
            new Meta($provider->name(), $responseData['model'] ?? $model)
        );
    }

    /**
     * Parse a single moderation result from API response.
     */
    protected function parseModerationResult(array $resultData): ModerationResult
    {
        return new ModerationResult(
            flagged: $resultData['flagged'] ?? false,
            categories: $resultData['categories'] ?? [],
            categoryScores: $resultData['category_scores'] ?? [],
        );
    }

    /**
     * Build HTTP client for OpenAI API.
     */
    protected function buildHttpClient(ModerationProvider $provider): PendingRequest
    {
        $credentials = $provider->providerCredentials();
        
        return Http::baseUrl('https://api.openai.com/v1')
            ->withHeaders([
                'Authorization' => 'Bearer '.$credentials['key'],
                'Content-Type' => 'application/json',
            ])
            ->timeout(30)
            ->throw();
    }
}
