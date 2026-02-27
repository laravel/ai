<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\ModerationProvider;
use Laravel\Ai\Responses\ModerationResponse;

interface ModerationGateway
{
    /**
     * Check the given input for content that may violate usage policies.
     */
    public function moderate(
        ModerationProvider $provider,
        string $model,
        string $input
    ): ModerationResponse;
}
