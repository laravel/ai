<?php

namespace Laravel\Ai\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

class CacheResponse
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected ?int $seconds = 3600,
        protected ?string $store = null,
    ) {}

    /**
     * Handle the incoming prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $key = $this->cacheKey($prompt);

        $cached = $this->cache()->get($key);

        if ($cached !== null) {
            return $this->restoreResponse($cached);
        }

        $response = $next($prompt);

        if ($response instanceof StreamableAgentResponse) {
            return $response;
        }

        $this->cache()->put(
            $key, $this->serializeResponse($response), $this->seconds
        );

        return $response;
    }

    /**
     * Generate a deterministic cache key for the given prompt.
     */
    protected function cacheKey(AgentPrompt $prompt): string
    {
        $hash = hash('sha256', implode('|', [
            get_class($prompt->agent),
            $prompt->provider->name(),
            $prompt->model,
            (string) $prompt->agent->instructions(),
            $prompt->prompt,
        ]));

        return "ai:text:{$hash}";
    }

    /**
     * Serialize an agent response for caching.
     */
    protected function serializeResponse(AgentResponse $response): array
    {
        $data = [
            'text' => $response->text,
            'usage' => $response->usage->toArray(),
            'meta' => [
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
            ],
        ];

        if ($response instanceof StructuredAgentResponse) {
            return [...$data, 'type' => 'structured', 'structured' => $response->structured];
        }

        return [...$data, 'type' => 'text'];
    }

    /**
     * Restore an agent response from cached data.
     */
    protected function restoreResponse(array $data): AgentResponse
    {
        $usage = new Usage(
            $data['usage']['prompt_tokens'],
            $data['usage']['completion_tokens'],
            $data['usage']['cache_write_input_tokens'],
            $data['usage']['cache_read_input_tokens'],
            $data['usage']['reasoning_tokens'],
        );

        $meta = new Meta(
            $data['meta']['provider'],
            $data['meta']['model'],
        );

        $invocationId = (string) Str::uuid7();

        if ($data['type'] === 'structured') {
            return new StructuredAgentResponse(
                $invocationId, $data['structured'], $data['text'], $usage, $meta
            );
        }

        return new AgentResponse($invocationId, $data['text'], $usage, $meta);
    }

    /**
     * Get the cache store instance.
     */
    protected function cache()
    {
        return Cache::store($this->store);
    }
}
