<?php

/**
 * This example demonstrates how to use the fine-tuning functionality
 * in the Laravel AI SDK.
 */

use Laravel\Ai\FineTuning;
use Laravel\Ai\Ai;

// Example 1: Creating a Fine-Tuning Job
$job = FineTuning::createJob(
    trainingFile: 'file-abc123',
    model: 'gpt-4o-mini-2024-07-18'
);

echo "Created job: {$job->id}\n";
echo "Status: {$job->status}\n";

// Example 2: Monitoring a Job
$job = FineTuning::retrieveJob('ft-abc123');

if ($job->isCompleted()) {
    echo "Job completed successfully!\n";
}

// Example 3: Listing Jobs
$jobs = FineTuning::listJobs();
foreach ($jobs as $job) {
    echo "{$job->id}: {$job->status}\n";
}
