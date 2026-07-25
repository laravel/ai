<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\TokenCountResponse;

interface TokenCountGateway
{
    /**
     * Count the tokens the given messages will consume before inference.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, mixed>  $providerOptions
     */
    public function countTokens(
        TextProvider $provider,
        string $model,
        array $messages,
        ?string $instructions = null,
        array $tools = [],
        int $timeout = 30,
        array $providerOptions = [],
    ): TokenCountResponse;
}
