<?php

namespace Laravel\Ai\Providers\Concerns;

use Laravel\Ai\Responses\FineTuningCheckpointListResponse;
use Laravel\Ai\Responses\FineTuningEventListResponse;
use Laravel\Ai\Responses\FineTuningJobListResponse;
use Laravel\Ai\Responses\FineTuningJobResponse;

trait ManagesFineTuning
{
    /**
     * Create a new fine-tuning job.
     */
    public function createJob(string $trainingFile, string $model, array $options = []): FineTuningJobResponse
    {
        return $this->fineTuningGateway()->createJob($this, $trainingFile, $model, $options);
    }

    /**
     * List all fine-tuning jobs.
     */
    public function listJobs(array $options = []): FineTuningJobListResponse
    {
        return $this->fineTuningGateway()->listJobs($this, $options);
    }

    /**
     * Retrieve a specific fine-tuning job.
     */
    public function retrieveJob(string $jobId): FineTuningJobResponse
    {
        return $this->fineTuningGateway()->retrieveJob($this, $jobId);
    }

    /**
     * Cancel a running fine-tuning job.
     */
    public function cancelJob(string $jobId): FineTuningJobResponse
    {
        return $this->fineTuningGateway()->cancelJob($this, $jobId);
    }

    /**
     * Pause a running fine-tuning job.
     */
    public function pauseJob(string $jobId): FineTuningJobResponse
    {
        return $this->fineTuningGateway()->pauseJob($this, $jobId);
    }

    /**
     * Resume a paused fine-tuning job.
     */
    public function resumeJob(string $jobId): FineTuningJobResponse
    {
        return $this->fineTuningGateway()->resumeJob($this, $jobId);
    }

    /**
     * List events for a specific fine-tuning job.
     */
    public function listJobEvents(string $jobId, array $options = []): FineTuningEventListResponse
    {
        return $this->fineTuningGateway()->listJobEvents($this, $jobId, $options);
    }

    /**
     * List checkpoints for a specific fine-tuning job.
     */
    public function listJobCheckpoints(string $jobId, array $options = []): FineTuningCheckpointListResponse
    {
        return $this->fineTuningGateway()->listJobCheckpoints($this, $jobId, $options);
    }
}
