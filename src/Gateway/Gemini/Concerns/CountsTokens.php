<?php

namespace Laravel\Ai\Gateway\Gemini\Concerns;

use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;

trait CountsTokens
{
    /**
     * Count tokens for a Gemini text generation request.
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
            $instructions,
            $messages,
            $tools,
        );

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->post("models/{$model}:countTokens", $body),
        );

        $data = $response->json();

        return $data['totalTokens'] ?? 0;
    }

    /**
     * Build the request body for token counting.
     */
    protected function buildTokenCountingBody(
        TextProvider $provider,
        ?string $instructions,
        array $messages,
        array $tools,
    ): array {
        $body = [
            'contents' => $this->mapMessages($messages),
        ];

        if (filled($instructions)) {
            $body['system_instruction'] = [
                'parts' => [
                    ['text' => $instructions],
                ],
            ];
        }

        $mappedTools = filled($tools) ? $this->mapTools($tools, $provider) : [];

        if (filled($mappedTools)) {
            $body['tools'] = $mappedTools;
        }

        return $body;
    }
}
