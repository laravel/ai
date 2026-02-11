<?php

namespace Laravel\Ai\Tracing\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class SendLangfuseTrace implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The backoff strategy for retries (in seconds).
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 30, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $payload,
        public string $url,
        public string $publicKey,
        public string $secretKey,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Http::withBasicAuth($this->publicKey, $this->secretKey)
            ->post(rtrim($this->url, '/').'/api/public/ingestion', $this->payload)
            ->throw();
    }
}
