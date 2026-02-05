<?php

namespace Laravel\Ai\Contracts\Providers;

use Laravel\Ai\Contracts\Gateway\FineTuningGateway;
use Laravel\Ai\Responses\FineTuningCheckpointListResponse;
use Laravel\Ai\Responses\FineTuningEventListResponse;
use Laravel\Ai\Responses\FineTuningJobListResponse;
use Laravel\Ai\Responses\FineTuningJobResponse;

interface FineTuningProvider
{
    /**
     * Create a new fine-tuning job.
     */
    public function createJob(string $trainingFile, string $model, array $options = []): FineTuningJobResponse;

    /**
     * List all fine-tuning jobs.
     */
    public function listJobs(array $options = []): FineTuningJobListResponse;

    /**
     * Retrieve a specific fine-tuning job.
     */
    public function retrieveJob(string $jobId): FineTuningJobResponse;

    /**
     * Cancel a running fine-tuning job.
     */
    public function cancelJob(string $jobId): FineTuningJobResponse;

    /**
     * Pause a running fine-tuning job.
     */
    public function pauseJob(string $jobId): FineTuningJobResponse;

    /**
     * Resume a paused fine-tuning job.
     */
    public function resumeJob(string $jobId): FineTuningJobResponse;

    /**
     * List events for a specific fine-tuning job.
     */
    public function listJobEvents(string $jobId, array $options = []): FineTuningEventListResponse;

    /**
     * List checkpoints for a specific fine-tuning job.
     */
    public function listJobCheckpoints(string $jobId, array $options = []): FineTuningCheckpointListResponse;

    /**
     * Get the provider's fine-tuning gateway.
     */
    public function fineTuningGateway(): FineTuningGateway;

    /**
     * Set the provider's fine-tuning gateway.
     */
    public function useFineTuningGateway(FineTuningGateway $gateway): self;
}
