<?php

namespace Laravel\Ai\Responses;

use Laravel\Ai\Responses\Data\Meta;

class FineTuningJobResponse
{
    public function __construct(
        public string $id,
        public string $status,
        public string $model,
        public string $trainingFile,
        public int $createdAt,
        public ?int $finishedAt = null,
        public ?array $error = null,
        public ?array $hyperparameters = null,
        public ?array $resultFiles = null,
        public ?int $trainedTokens = null,
        public ?Meta $meta = null,
    ) {}

    /**
     * Check if the job is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'succeeded';
    }

    /**
     * Check if the job is running.
     */
    public function isRunning(): bool
    {
        return in_array($this->status, ['running', 'queued', 'validating_files']);
    }

    /**
     * Check if the job has failed.
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the job was cancelled.
     */
    public function wasCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
