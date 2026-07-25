<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\TokenCountResponse;

interface CountsTokens
{
    /**
     * Count the tokens the given messages will consume before inference.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, mixed>  $providerOptions
     */
    public function countTokens(
        array $messages,
        ?string $instructions = null,
        array $tools = [],
        ?string $model = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): TokenCountResponse;

    /**
     * Count the tokens the given agent prompt will consume before inference.
     *
     * @param  array<string, mixed>  $providerOptions
     */
    public function countTokensFor(
        Agent $agent,
        string $prompt,
        array $attachments = [],
        ?string $model = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): TokenCountResponse;
}
