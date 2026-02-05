<?php

namespace Laravel\Ai\Responses;

class FineTuningCheckpointResponse
{
    public function __construct(
        public string $id,
        public int $stepNumber,
        public array $metrics,
        public string $fineTunedModelCheckpoint,
        public int $createdAt,
    ) {}
}
