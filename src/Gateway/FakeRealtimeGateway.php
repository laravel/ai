<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Laravel\Ai\Contracts\Gateway\RealtimeGateway;
use Laravel\Ai\Contracts\Providers\RealtimeProvider;
use Laravel\Ai\Prompts\RealtimePrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\RealtimeSession;
use RuntimeException;

class FakeRealtimeGateway implements RealtimeGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStraySessions = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * Create an ephemeral realtime session.
     */
    public function createRealtimeSession(
        RealtimeProvider $provider,
        RealtimePrompt $prompt,
    ): RealtimeSession {
        return $this->nextResponse($provider, $prompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(RealtimeProvider $provider, RealtimePrompt $prompt): RealtimeSession
    {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $prompt);

        return tap($this->marshalResponse(
            $response, $provider, $prompt
        ), fn (): int => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a full RealtimeSession instance.
     */
    protected function marshalResponse(
        mixed $response,
        RealtimeProvider $provider,
        RealtimePrompt $prompt,
    ): RealtimeSession {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStraySessions) {
                throw new RuntimeException('Attempted realtime session creation without a fake response.');
            }

            return new RealtimeSession(
                id: 'sess_fake_'.uniqid(),
                clientSecret: 'ek_fake_'.uniqid(),
                expiresAt: time() + 3600,
                model: $prompt->model,
                meta: new Meta($provider->name(), $prompt->model),
                voice: $prompt->voice,
                modalities: $prompt->modalities,
            );
        }

        if (is_array($response)) {
            return new RealtimeSession(
                id: $response['id'] ?? 'sess_fake_'.uniqid(),
                clientSecret: $response['client_secret'] ?? ($response['token'] ?? 'ek_fake_'.uniqid()),
                expiresAt: $response['expires_at'] ?? (time() + 3600),
                model: $response['model'] ?? $prompt->model,
                meta: new Meta($provider->name(), $response['model'] ?? $prompt->model),
                voice: $response['voice'] ?? $prompt->voice,
                modalities: $response['modalities'] ?? $prompt->modalities,
                raw: $response,
            );
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any realtime session is not faked.
     */
    public function preventStrayRealtime(bool $prevent = true): self
    {
        $this->preventStraySessions = $prevent;

        return $this;
    }
}
