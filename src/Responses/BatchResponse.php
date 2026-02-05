<?php

namespace Laravel\Ai\Responses;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Laravel\Ai\Responses\Data\BatchError;
use Laravel\Ai\Responses\Data\BatchRequestCounts;
use Laravel\Ai\Responses\Data\Meta;

class BatchResponse implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $id,
        public string $object,
        public string $endpoint,
        public string $inputFileId,
        public ?string $outputFileId,
        public ?string $errorFileId,
        public string $status,
        public string $completionWindow,
        public int $createdAt,
        public ?int $completedAt,
        public ?int $failedAt,
        public ?int $cancelledAt,
        public ?int $expiresAt,
        public BatchRequestCounts $requestCounts,
        public array $errors,
        public array $metadata,
        public Meta $meta,
    ) {}

    /**
     * Get the instance as an array.
     */
    public function toArray()
    {
        return [
            'id' => $this->id,
            'object' => $this->object,
            'endpoint' => $this->endpoint,
            'input_file_id' => $this->inputFileId,
            'output_file_id' => $this->outputFileId,
            'error_file_id' => $this->errorFileId,
            'status' => $this->status,
            'completion_window' => $this->completionWindow,
            'created_at' => $this->createdAt,
            'completed_at' => $this->completedAt,
            'failed_at' => $this->failedAt,
            'cancelled_at' => $this->cancelledAt,
            'expires_at' => $this->expiresAt,
            'request_counts' => $this->requestCounts->toArray(),
            'errors' => array_map(fn ($error) => $error->toArray(), $this->errors),
            'metadata' => $this->metadata,
            'meta' => $this->meta->toArray(),
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
