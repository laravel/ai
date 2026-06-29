<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;

trait CountsTokens
{
    /**
     * Count tokens for an Anthropic text generation request.
     *
     * @param  Message[]  $messages
     * @param  Tool[]  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function countTokens(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
    ): int {
        $body = $this->buildTokenCountingBody(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->post('messages/count_tokens', $body),
        );

        $data = $response->json();

        return $data['input_tokens'] ?? 0;
    }

    /**
     * Build the request body for token counting.
     */
    protected function buildTokenCountingBody(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
    ): array {
        $body = [
            'model' => $model,
            'messages' => $this->mapMessages($messages),
        ];

        if (filled($instructions)) {
            $body['system'] = $instructions;
        }

        $mappedTools = filled($tools) ? $this->mapTools($tools, $provider) : [];

        if (filled($schema)) {
            $mappedTools[] = $this->buildStructuredOutputTool($schema);
        }

        if (filled($mappedTools)) {
            $body['tools'] = $mappedTools;
        }

        return $body;
    }
}
