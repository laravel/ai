<?php

namespace Laravel\Ai\Providers\Concerns;

use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;

trait CountsTokens
{
    /**
     * Count tokens for a text generation request.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function countTokens(
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
    ): int {
        return $this->gateway->countTokens(
            $this,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
        );
    }
}
