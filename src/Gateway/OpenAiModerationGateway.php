<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\ModerationGateway;
use Laravel\Ai\Contracts\Providers\ModerationProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ModerationCategory;
use Laravel\Ai\Responses\ModerationResponse;

class OpenAiModerationGateway implements ModerationGateway
{
    /**
     * Check the given input for content that may violate usage policies.
     */
    public function moderate(
        ModerationProvider $provider,
        string $model,
        string $input
    ): ModerationResponse {
        $response = $this->client($provider)->post('/moderations', [
            'model' => $model,
            'input' => $input,
        ]);

        $data = $response->json();

        $result = $data['results'][0];

        $categories = (new Collection($result['categories']))->map(
            fn (bool $flagged, string $name) => new ModerationCategory(
                name: $name,
                flagged: $flagged,
                score: $result['category_scores'][$name] ?? 0.0,
            )
        )->values()->all();

        return new ModerationResponse(
            $result['flagged'],
            $categories,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get an HTTP client for the OpenAI API.
     */
    protected function client(ModerationProvider $provider): PendingRequest
    {
        return Http::baseUrl('https://api.openai.com/v1')
            ->withHeaders([
                'Authorization' => 'Bearer '.$provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ])
            ->throw();
    }
}
