<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\ModerationProvider;
use Laravel\Ai\Responses\ModerationResponse;

interface ModerationGateway
{
    /**
     * Moderate the given input(s).
     *
     * @param  string|string[]  $input
     */
    public function moderate(ModerationProvider $provider, string $model, string|array $input): ModerationResponse;
}
