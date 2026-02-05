<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\ModerationGateway;
use Laravel\Ai\Responses\ModerationResponse;

interface ModerationProvider
{
    /**
     * Moderate the given input(s).
     *
     * @param  string|string[]  $input
     */
    public function moderate(string|array $input, ?string $model = null): ModerationResponse;

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
