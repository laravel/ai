<?php

namespace Laravel\Ai\Gateway;

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Gateway\FineTuningGateway;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\FineTuningCheckpointListResponse;
use Laravel\Ai\Responses\FineTuningCheckpointResponse;
use Laravel\Ai\Responses\FineTuningEventListResponse;
use Laravel\Ai\Responses\FineTuningEventResponse;
use Laravel\Ai\Responses\FineTuningJobListResponse;
use Laravel\Ai\Responses\FineTuningJobResponse;
use Laravel\Ai\Responses\Data\Meta;

class OpenAiFineTuningGateway implements FineTuningGateway
{
    use Concerns\HandlesRateLimiting;

    /**
     * Create a new fine-tuning job.
     */
    public function createJob(Provider $provider, string $trainingFile, string $model, array $options = []): FineTuningJobResponse
    {
        $payload = array_filter([
            'training_file' => $trainingFile,
            'model' => $model,
            'hyperparameters' => $options['hyperparameters'] ?? null,
            'suffix' => $options['suffix'] ?? null,
            'validation_file' => $options['validation_file'] ?? null,
            'integrations' => $options['integrations'] ?? null,
            'seed' => $options['seed'] ?? null,
        ]);

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->post('https://api.openai.com/v1/fine_tuning/jobs', $payload)
                ->throw()
        );

        return $this->parseJobResponse($response->json(), $provider);
    }

    /**
     * List all fine-tuning jobs.
     */
    public function listJobs(Provider $provider, array $options = []): FineTuningJobListResponse
    {
        $query = array_filter([
            'after' => $options['after'] ?? null,
            'limit' => $options['limit'] ?? null,
        ]);

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->get('https://api.openai.com/v1/fine_tuning/jobs', $query)
                ->throw()
        );

        $data = $response->json();

        $jobs = array_map(
            fn ($job) => $this->parseJobResponse($job, $provider),
            $data['data'] ?? []
        );

        return new FineTuningJobListResponse(
            jobs: $jobs,
            hasMore: $data['has_more'] ?? false,
        );
    }

    /**
     * Retrieve a specific fine-tuning job.
     */
    public function retrieveJob(Provider $provider, string $jobId): FineTuningJobResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->get("https://api.openai.com/v1/fine_tuning/jobs/{$jobId}")
                ->throw()
        );

        return $this->parseJobResponse($response->json(), $provider);
    }

    /**
     * Cancel a running fine-tuning job.
     */
    public function cancelJob(Provider $provider, string $jobId): FineTuningJobResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->post("https://api.openai.com/v1/fine_tuning/jobs/{$jobId}/cancel")
                ->throw()
        );

        return $this->parseJobResponse($response->json(), $provider);
    }

    /**
     * Pause a running fine-tuning job.
     */
    public function pauseJob(Provider $provider, string $jobId): FineTuningJobResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->post("https://api.openai.com/v1/fine_tuning/jobs/{$jobId}/pause")
                ->throw()
        );

        return $this->parseJobResponse($response->json(), $provider);
    }

    /**
     * Resume a paused fine-tuning job.
     */
    public function resumeJob(Provider $provider, string $jobId): FineTuningJobResponse
    {
        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->post("https://api.openai.com/v1/fine_tuning/jobs/{$jobId}/resume")
                ->throw()
        );

        return $this->parseJobResponse($response->json(), $provider);
    }

    /**
     * List events for a specific fine-tuning job.
     */
    public function listJobEvents(Provider $provider, string $jobId, array $options = []): FineTuningEventListResponse
    {
        $query = array_filter([
            'after' => $options['after'] ?? null,
            'limit' => $options['limit'] ?? null,
        ]);

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->get("https://api.openai.com/v1/fine_tuning/jobs/{$jobId}/events", $query)
                ->throw()
        );

        $data = $response->json();

        $events = array_map(
            fn ($event) => new FineTuningEventResponse(
                id: $event['id'],
                type: $event['type'] ?? 'message',
                message: $event['message'] ?? '',
                createdAt: $event['created_at'],
                level: $event['level'] ?? null,
            ),
            $data['data'] ?? []
        );

        return new FineTuningEventListResponse(
            events: $events,
            hasMore: $data['has_more'] ?? false,
        );
    }

    /**
     * List checkpoints for a specific fine-tuning job.
     */
    public function listJobCheckpoints(Provider $provider, string $jobId, array $options = []): FineTuningCheckpointListResponse
    {
        $query = array_filter([
            'after' => $options['after'] ?? null,
            'limit' => $options['limit'] ?? null,
        ]);

        $response = $this->withRateLimitHandling(
            $provider->name(),
            fn () => Http::withToken($provider->providerCredentials()['key'])
                ->get("https://api.openai.com/v1/fine_tuning/jobs/{$jobId}/checkpoints", $query)
                ->throw()
        );

        $data = $response->json();

        $checkpoints = array_map(
            fn ($checkpoint) => new FineTuningCheckpointResponse(
                id: $checkpoint['id'],
                stepNumber: $checkpoint['step_number'],
                metrics: $checkpoint['metrics'] ?? [],
                fineTunedModelCheckpoint: $checkpoint['fine_tuned_model_checkpoint'],
                createdAt: $checkpoint['created_at'],
            ),
            $data['data'] ?? []
        );

        return new FineTuningCheckpointListResponse(
            checkpoints: $checkpoints,
            hasMore: $data['has_more'] ?? false,
        );
    }

    /**
     * Parse a job response from OpenAI API.
     */
    protected function parseJobResponse(array $data, Provider $provider): FineTuningJobResponse
    {
        return new FineTuningJobResponse(
            id: $data['id'],
            status: $data['status'],
            model: $data['model'],
            trainingFile: $data['training_file'],
            createdAt: $data['created_at'],
            finishedAt: $data['finished_at'] ?? null,
            error: $data['error'] ?? null,
            hyperparameters: $data['hyperparameters'] ?? null,
            resultFiles: $data['result_files'] ?? null,
            trainedTokens: $data['trained_tokens'] ?? null,
            meta: new Meta(
                provider: $provider->name(),
                model: $data['model'],
            ),
        );
    }
}
