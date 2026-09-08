<?php

namespace Laravel\Ai\Contracts\Harness;

use Generator;
use Laravel\Ai\Harness\HarnessResult;
use Laravel\Ai\Harness\HarnessRun;
use Laravel\Ai\Streaming\Events\StreamEvent;

interface Runtime
{
    /** @return Generator<int, StreamEvent, mixed, HarnessResult> */
    public function run(HarnessRun $run): Generator;
}
