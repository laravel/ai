<?php

namespace Laravel\Ai\Prompts;

use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Contracts\Providers\TextProvider;

abstract class Prompt
{
    public function __construct(
        public readonly string $prompt,
        public readonly TextProvider $provider,
        public readonly string $model,
        public readonly ?Decision $resume = null,
    ) {}
}
