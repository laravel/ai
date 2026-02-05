<?php

namespace Laravel\Ai\Gateway;

use Closure;
use Laravel\Ai\Contracts\Gateway\FineTuningGateway;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\FineTuningCheckpointListResponse;
use Laravel\Ai\Responses\FineTuningCheckpointResponse;
use Laravel\Ai\Responses\FineTuningEventListResponse;
use Laravel\Ai\Responses\FineTuningEventResponse;
use Laravel\Ai\Responses\FineTuningJobListResponse;
use Laravel\Ai\Responses\FineTuningJobResponse;
use RuntimeException;

class FakeFineTuningGateway implements FineTuningGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayOperations = false;

    public function __construct(
        protected Closure|array $responses = [],
    ) {}

    /**
     * Create a new fine-tuning job.
     */
    public function createJob(Provider $provider, string $trainingFile, string $model, array $options = []): FineTuningJobResponse
    {
        return $this->nextResponse($provider, 'createJob');
    }

    /**
     * List all fine-tuning jobs.
     */
    public function listJobs(Provider $provider, array $options = []): FineTuningJobListResponse
    {
        return $this->nextResponse($provider, 'listJobs');
    }

    /**
     * Retrieve a specific fine-tuning job.
     */
    public function retrieveJob(Provider $provider, string $jobId): FineTuningJobResponse
    {
        return $this->nextResponse($provider, 'retrieveJob');
    }

    /**
     * Cancel a running fine-tuning job.
     */
    public function cancelJob(Provider $provider, string $jobId): FineTuningJobResponse
    {
        return $this->nextResponse($provider, 'cancelJob');
    }

    /**
     * Pause a running fine-tuning job.
     */
    public function pauseJob(Provider $provider, string $jobId): FineTuningJobResponse
    {
        return $this->nextResponse($provider, 'pauseJob');
    }

    /**
     * Resume a paused fine-tuning job.
     */
    public function resumeJob(Provider $provider, string $jobId): FineTuningJobResponse
    {
        return $this->nextResponse($provider, 'resumeJob');
    }

    /**
     * List events for a specific fine-tuning job.
     */
    public function listJobEvents(Provider $provider, string $jobId, array $options = []): FineTuningEventListResponse
    {
        return $this->nextResponse($provider, 'listJobEvents');
    }

    /**
     * List checkpoints for a specific fine-tuning job.
     */
    public function listJobCheckpoints(Provider $provider, string $jobId, array $options = []): FineTuningCheckpointListResponse
    {
        return $this->nextResponse($provider, 'listJobCheckpoints');
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(Provider $provider, string $operation): mixed
    {
        $response = is_array($this->responses)
            ? ($this->responses[$this->currentResponseIndex] ?? null)
            : call_user_func($this->responses, $operation, $provider);

        return tap($this->marshalResponse(
            $response, $provider, $operation
        ), fn () => $this->currentResponseIndex++);
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(mixed $response, Provider $provider, string $operation): mixed
    {
        if ($response instanceof Closure) {
            $response = $response($operation, $provider);
        }

        if (is_null($response)) {
            if ($this->preventStrayOperations) {
                throw new RuntimeException('Attempted fine-tuning operation without a fake response.');
            }

            return $this->generateFakeResponse($provider, $operation);
        }

        return $response;
    }

    /**
     * Generate a fake response for the given operation.
     */
    protected function generateFakeResponse(Provider $provider, string $operation): mixed
    {
        return match ($operation) {
            'createJob', 'retrieveJob', 'cancelJob', 'pauseJob', 'resumeJob' => new FineTuningJobResponse(
                id: 'ft-job-' . bin2hex(random_bytes(8)),
                status: 'queued',
                model: 'gpt-4o-mini-2024-07-18',
                trainingFile: 'file-' . bin2hex(random_bytes(8)),
                createdAt: time(),
                meta: new Meta($provider->name(), 'gpt-4o-mini-2024-07-18'),
            ),
            'listJobs' => new FineTuningJobListResponse(
                jobs: [
                    new FineTuningJobResponse(
                        id: 'ft-job-' . bin2hex(random_bytes(8)),
                        status: 'queued',
                        model: 'gpt-4o-mini-2024-07-18',
                        trainingFile: 'file-' . bin2hex(random_bytes(8)),
                        createdAt: time(),
                        meta: new Meta($provider->name(), 'gpt-4o-mini-2024-07-18'),
                    ),
                ],
                hasMore: false,
            ),
            'listJobEvents' => new FineTuningEventListResponse(
                events: [
                    new FineTuningEventResponse(
                        id: 'event-' . bin2hex(random_bytes(8)),
                        type: 'message',
                        message: 'Job queued',
                        createdAt: time(),
                        level: 'info',
                    ),
                ],
                hasMore: false,
            ),
            'listJobCheckpoints' => new FineTuningCheckpointListResponse(
                checkpoints: [],
                hasMore: false,
            ),
            default => throw new RuntimeException("Unknown operation: {$operation}"),
        };
    }

    /**
     * Indicate that an exception should be thrown if any operation is not faked.
     */
    public function preventStrayFineTuning(bool $prevent = true): self
    {
        $this->preventStrayOperations = $prevent;

        return $this;
    }
}
