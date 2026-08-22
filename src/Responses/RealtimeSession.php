<?php

namespace Laravel\Ai\Responses;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Responses\Data\Meta;

class RealtimeSession implements Arrayable, JsonSerializable
{
    /**
     * Create a new realtime session instance.
     */
    public function __construct(
        public string $id,
        public string $clientSecret,
        public int $expiresAt,
        public string $model,
        public Meta $meta,
        public ?string $voice = null,
        public array $modalities = ['text', 'audio'],
        public array $raw = [],
    ) {
        //
    }

    /**
     * Get the session ID.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Get the ephemeral client secret (token).
     */
    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    /**
     * Get the expiration timestamp of the client secret.
     */
    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * Get the model configured for the session.
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * Get the voice configured for the session.
     */
    public function voice(): ?string
    {
        return $this->voice;
    }

    /**
     * Get the modalities enabled for the session.
     */
    public function modalities(): array
    {
        return $this->modalities;
    }

    /**
     * Get the raw provider response.
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Convert the session to an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'client_secret' => $this->clientSecret,
            'expires_at' => $this->expiresAt,
            'model' => $this->model,
            'voice' => $this->voice,
            'modalities' => $this->modalities,
        ];
    }

    /**
     * Specify data which should be serialized to JSON.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
