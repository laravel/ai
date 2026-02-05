<?php

namespace Laravel\Ai;

use Laravel\Ai\Contracts\Providers\FineTuningProvider;
use Laravel\Ai\Responses\FineTuningCheckpointListResponse;
use Laravel\Ai\Responses\FineTuningEventListResponse;
use Laravel\Ai\Responses\FineTuningJobListResponse;
use Laravel\Ai\Responses\FineTuningJobResponse;

class FineTuning
{
    /**
     * The provider to use for fine-tuning operations.
     */
    protected static ?string $provider = null;

    /**
     * Set the provider for fine-tuning operations.
     */
    public static function using(?string $provider): self
    {
        $instance = new self;
        static::$provider = $provider;

        return $instance;
    }

    /**
     * Create a new fine-tuning job.
     */
    public static function createJob(string $trainingFile, string $model, array $options = []): FineTuningJobResponse
    {
        return static::provider()->createJob($trainingFile, $model, $options);
    }

    /**
     * List all fine-tuning jobs.
     */
    public static function listJobs(array $options = []): FineTuningJobListResponse
    {
        return static::provider()->listJobs($options);
    }

    /**
     * Retrieve a specific fine-tuning job.
     */
    public static function retrieveJob(string $jobId): FineTuningJobResponse
    {
        return static::provider()->retrieveJob($jobId);
    }

    /**
     * Cancel a running fine-tuning job.
     */
    public static function cancelJob(string $jobId): FineTuningJobResponse
    {
        return static::provider()->cancelJob($jobId);
    }

    /**
     * Pause a running fine-tuning job.
     */
    public static function pauseJob(string $jobId): FineTuningJobResponse
    {
        return static::provider()->pauseJob($jobId);
    }

    /**
     * Resume a paused fine-tuning job.
     */
    public static function resumeJob(string $jobId): FineTuningJobResponse
    {
        return static::provider()->resumeJob($jobId);
    }

    /**
     * List events for a specific fine-tuning job.
     */
    public static function listJobEvents(string $jobId, array $options = []): FineTuningEventListResponse
    {
        return static::provider()->listJobEvents($jobId, $options);
    }

    /**
     * List checkpoints for a specific fine-tuning job.
     */
    public static function listJobCheckpoints(string $jobId, array $options = []): FineTuningCheckpointListResponse
    {
        return static::provider()->listJobCheckpoints($jobId, $options);
    }

    /**
     * Get the fine-tuning provider instance.
     */
    protected static function provider(): FineTuningProvider
    {
        $provider = Ai::fineTuningProvider(static::$provider);

        static::$provider = null;

        return $provider;
    }

    /**
     * Determine if fine-tuning is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::fineTuningIsFaked();
    }
}
