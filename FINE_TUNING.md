# Fine-Tuning Support

This Laravel AI SDK now supports fine-tuning operations through OpenAI's API.

## Basic Usage

### Creating a Fine-Tuning Job

```php
use Laravel\Ai\FineTuning;

// Create a fine-tuning job with minimal options
$job = FineTuning::createJob(
    trainingFile: 'file-abc123',
    model: 'gpt-4o-mini-2024-07-18'
);

// Create a fine-tuning job with hyperparameters
$job = FineTuning::createJob(
    trainingFile: 'file-abc123',
    model: 'gpt-4o-mini-2024-07-18',
    options: [
        'hyperparameters' => [
            'n_epochs' => 3,
            'batch_size' => 4,
            'learning_rate_multiplier' => 0.1,
        ],
        'suffix' => 'my-custom-model',
        'validation_file' => 'file-xyz789',
    ]
);

echo $job->id; // ft-abc123
echo $job->status; // 'queued', 'running', 'succeeded', etc.
```

### Using a Specific Provider

```php
// Use OpenAI explicitly
$job = FineTuning::using('openai')->createJob('file-abc123', 'gpt-4o-mini-2024-07-18');
```

### Listing Fine-Tuning Jobs

```php
// List all jobs
$jobs = FineTuning::listJobs();

foreach ($jobs as $job) {
    echo $job->id . ': ' . $job->status . PHP_EOL;
}

// List with pagination
$jobs = FineTuning::listJobs([
    'limit' => 10,
    'after' => 'ft-abc123',
]);

echo $jobs->hasMore ? 'More jobs available' : 'No more jobs';
```

### Retrieving a Specific Job

```php
$job = FineTuning::retrieveJob('ft-abc123');

echo "Status: {$job->status}\n";
echo "Model: {$job->model}\n";

// Check job status
if ($job->isCompleted()) {
    echo "Job completed successfully!\n";
} elseif ($job->isRunning()) {
    echo "Job is still running...\n";
} elseif ($job->hasFailed()) {
    echo "Job failed: " . json_encode($job->error) . "\n";
}
```

### Managing Jobs

```php
// Cancel a running job
$job = FineTuning::cancelJob('ft-abc123');

// Pause a running job
$job = FineTuning::pauseJob('ft-abc123');

// Resume a paused job
$job = FineTuning::resumeJob('ft-abc123');
```

### Listing Job Events

```php
$events = FineTuning::listJobEvents('ft-abc123');

foreach ($events as $event) {
    echo "[{$event->level}] {$event->message}\n";
}

// List with pagination
$events = FineTuning::listJobEvents('ft-abc123', [
    'limit' => 20,
    'after' => 'event-xyz',
]);
```

### Listing Job Checkpoints

```php
$checkpoints = FineTuning::listJobCheckpoints('ft-abc123');

foreach ($checkpoints as $checkpoint) {
    echo "Step {$checkpoint->stepNumber}: ";
    echo json_encode($checkpoint->metrics) . "\n";
}
```

## Configuration

The default provider for fine-tuning can be configured in `config/ai.php`:

```php
return [
    'default_for_fine_tuning' => 'openai',
    // ...
];
```

## Response Objects

### FineTuningJobResponse

- `id`: Job identifier
- `status`: Current status (queued, running, succeeded, failed, cancelled)
- `model`: Base model being fine-tuned
- `trainingFile`: Training file ID
- `createdAt`: Unix timestamp of creation
- `finishedAt`: Unix timestamp of completion (nullable)
- `error`: Error details if failed (nullable)
- `hyperparameters`: Hyperparameters used (nullable)
- `resultFiles`: Result file IDs (nullable)
- `trainedTokens`: Number of tokens trained (nullable)

Helper methods:
- `isCompleted()`: Check if job succeeded
- `isRunning()`: Check if job is running
- `hasFailed()`: Check if job failed
- `wasCancelled()`: Check if job was cancelled

### FineTuningJobListResponse

- `jobs`: Array of FineTuningJobResponse objects
- `hasMore`: Boolean indicating if more results are available

### FineTuningEventResponse

- `id`: Event identifier
- `type`: Event type
- `message`: Event message
- `createdAt`: Unix timestamp
- `level`: Log level (info, warning, error)

### FineTuningCheckpointResponse

- `id`: Checkpoint identifier
- `stepNumber`: Training step number
- `metrics`: Training metrics (loss, accuracy, etc.)
- `fineTunedModelCheckpoint`: Model checkpoint identifier
- `createdAt`: Unix timestamp

## Testing

You can fake fine-tuning operations in tests:

```php
use Laravel\Ai\Ai;
use Laravel\Ai\FineTuning;
use Laravel\Ai\Responses\FineTuningJobResponse;

// Fake with a specific response
Ai::fakeFineTuning([
    new FineTuningJobResponse(
        id: 'ft-test',
        status: 'succeeded',
        model: 'gpt-4o-mini',
        trainingFile: 'file-test',
        createdAt: time(),
    ),
]);

// Use fine-tuning as normal
$job = FineTuning::createJob('file-abc', 'gpt-4o-mini');
```
