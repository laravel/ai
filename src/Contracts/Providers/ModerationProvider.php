<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\ModerationGateway;
use Laravel\Ai\Responses\ModerationResponse;

interface ModerationProvider
{
    /**
     * Check the given input for content that may violate usage policies.
     */
    public function moderate(string $input, ?string $model = null): ModerationResponse;

    /**
     * Get the provider's moderation gateway.
     */
    public function moderationGateway(): ModerationGateway;

    /**
     * Set the provider's moderation gateway.
     */
    public function useModerationGateway(ModerationGateway $gateway): self;

    /**
     * Get the name of the default moderation model.
     */
    public function defaultModerationModel(): string;
}
