<?php

namespace Laravel\Ai\Responses\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\Response as HttpResponse;
use JsonSerializable;

class Step implements Arrayable, JsonSerializable
{
    protected ?HttpResponse $raw = null;

    /**
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<int, ToolResult>  $toolResults
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public array $toolResults,
        public FinishReason $finishReason,
        public Usage $usage,
        public Meta $meta,
    ) {}

    /**
     * Set the raw HTTP response.
     */
    public function withRaw(?HttpResponse $response): static
    {
        $this->raw = $response;

        return $this;
    }

    /**
     * Get the raw HTTP response, if available for the provider.
     */
    public function getRaw(): ?HttpResponse
    {
        return $this->raw;
    }

    /**
     * Prepare the step for serialization, discarding the unserializable raw HTTP response.
     */
    public function __serialize(): array
    {
        return collect(get_object_vars($this))->except('raw')->all();
    }

    /**
     * Restore the step after serialization.
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'tool_calls' => $this->toolCalls,
            'tool_results' => $this->toolResults,
            'finish_reason' => $this->finishReason->value,
            'usage' => $this->usage,
            'meta' => $this->meta,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
