<?php

namespace Laravel\Ai\PendingResponses;

use Illuminate\Support\Traits\Conditionable;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\ModerationResponse;

class PendingModeration
{
    use Conditionable;

    /**
     * Create a new pending moderation instance.
     */
    public function __construct(
        protected string $input,
    ) {}

    /**
     * Check the input for content that may violate usage policies.
     */
    public function check(Lab|array|string|null $provider = null, ?string $model = null): ModerationResponse
    {
        $providers = Provider::formatProviderAndModelList(
            $provider ?? config('ai.default_for_moderation'), $model
        );

        foreach ($providers as $provider => $model) {
            $provider = Ai::fakeableModerationProvider($provider);

            $model ??= $provider->defaultModerationModel();

            try {
                return $provider->moderate($this->input, $model);
            } catch (FailoverableException $e) {
                event(new ProviderFailedOver($provider, $model, $e));

                continue;
            }
        }

        throw $e;
    }
}
