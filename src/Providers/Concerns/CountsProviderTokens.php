<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\Gateway\TokenCountGateway;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\TokenCountResponse;
use LogicException;

trait CountsProviderTokens
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
    ): TokenCountResponse {
        $gateway = $this->textGateway();

        if (! $gateway instanceof TokenCountGateway) {
            throw new LogicException('The ['.$this->name().'] text gateway does not support token counting.');
        }

        return $gateway->countTokens(
            $this,
            $model ?? $this->defaultTextModel(),
            $messages,
            $instructions,
            $tools,
            $timeout,
            $providerOptions,
        );
    }

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
    ): TokenCountResponse {
        $messages = $this->withoutForeignProviderContentBlocks([
            ...($agent instanceof Conversational ? $agent->messages() : []),
        ]);

        $messages[] = new UserMessage($prompt, $attachments);

        return $this->countTokens(
            $messages,
            (string) $agent->instructions(),
            $this->resolveTools($agent),
            $model,
            $timeout,
            $providerOptions,
        );
    }
}
