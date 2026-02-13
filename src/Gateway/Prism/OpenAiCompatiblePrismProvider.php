<?php

namespace Laravel\Ai\Gateway\Prism;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Providers\Groq\Groq;
use Psr\Http\Message\RequestInterface;

class OpenAiCompatiblePrismProvider extends Groq
{
    /**
     * {@inheritdoc}
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        return parent::client($options, $retry, $baseUrl)
            ->withRequestMiddleware(fn (RequestInterface $request) => $this->sanitizeRequestBody($request));
    }

    /**
     * Sanitize the JSON request body to ensure compatibility with strict
     * OpenAI-compatible APIs (e.g. Gemini/Kodizm proxies).
     *
     * Handles two concerns:
     * 1. Empty `properties` in tool schemas: `[]` → `{}`
     * 2. Empty `arguments` in tool_calls within messages: `"[]"` → `"{}"`
     */
    protected function sanitizeRequestBody(RequestInterface $request): RequestInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (! str_contains($contentType, 'json')) {
            return $request;
        }

        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        if (! is_array($data)) {
            return $request;
        }

        $modified = false;

        if (! empty($data['tools'])) {
            $data['tools'] = $this->sanitizeToolSchemas($data['tools'], $modified);
        }

        if (! empty($data['messages'])) {
            $data['messages'] = $this->sanitizeMessageToolCalls($data['messages'], $modified);
        }

        if (! $modified) {
            return $request;
        }

        return $request->withBody(
            Utils::streamFor(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
        );
    }

    /**
     * Ensure tool parameter schemas use JSON objects for `properties`
     * fields, preventing empty PHP arrays from serializing as `[]`.
     *
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function sanitizeToolSchemas(array $tools, bool &$modified): array
    {
        foreach ($tools as &$tool) {
            $params = $tool['function']['parameters'] ?? null;

            if (! is_array($params)) {
                continue;
            }

            $fixed = $this->ensureObjectProperties($params);

            if ($fixed !== $params) {
                $tool['function']['parameters'] = $fixed;
                $modified = true;
            }
        }

        return $tools;
    }

    /**
     * Rewrite empty `arguments` in tool_calls from `"[]"` to `"{}"`
     * so that strict APIs expecting a JSON object (Struct) don't reject
     * the payload with "cannot start list" errors.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected function sanitizeMessageToolCalls(array $messages, bool &$modified): array
    {
        foreach ($messages as &$message) {
            if (empty($message['tool_calls'])) {
                continue;
            }

            foreach ($message['tool_calls'] as &$toolCall) {
                $arguments = $toolCall['function']['arguments'] ?? null;

                if ($arguments === '[]') {
                    $toolCall['function']['arguments'] = '{}';
                    $modified = true;
                }
            }
        }

        return $messages;
    }

    /**
     * Recursively cast empty `properties` arrays to objects so they
     * serialize as `{}` instead of `[]` during json_encode.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function ensureObjectProperties(array $schema): array
    {
        if (array_key_exists('properties', $schema) && $schema['properties'] === []) {
            $schema['properties'] = (object) [];
        } elseif (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = $this->ensureObjectProperties($property);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->ensureObjectProperties($schema['items']);
        }

        return $schema;
    }
}
