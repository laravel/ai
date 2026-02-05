<?php

namespace Laravel\Ai\Contracts\Gateway;

use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\FineTuningCheckpointListResponse;
use Laravel\Ai\Responses\FineTuningEventListResponse;
use Laravel\Ai\Responses\FineTuningJobListResponse;
use Laravel\Ai\Responses\FineTuningJobResponse;

interface FineTuningGateway
{
    /**
     * Create a new fine-tuning job.
     */
    public function createJob(Provider $provider, string $trainingFile, string $model, array $options = []): FineTuningJobResponse;

    /**
     * List all fine-tuning jobs.
     */
    public function listJobs(Provider $provider, array $options = []): FineTuningJobListResponse;

    /**
     * Retrieve a specific fine-tuning job.
     */
    public function retrieveJob(Provider $provider, string $jobId): FineTuningJobResponse;

    /**
     * Cancel a running fine-tuning job.
     */
    public function cancelJob(Provider $provider, string $jobId): FineTuningJobResponse;

    /**
     * Pause a running fine-tuning job.
     */
    public function pauseJob(Provider $provider, string $jobId): FineTuningJobResponse;

    /**
     * Resume a paused fine-tuning job.
     */
    public function resumeJob(Provider $provider, string $jobId): FineTuningJobResponse;

    /**
     * List events for a specific fine-tuning job.
     */
    public function listJobEvents(Provider $provider, string $jobId, array $options = []): FineTuningEventListResponse;

    /**
     * List checkpoints for a specific fine-tuning job.
     */
    public function listJobCheckpoints(Provider $provider, string $jobId, array $options = []): FineTuningCheckpointListResponse;
}
