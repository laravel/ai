<?php

namespace Laravel\Ai\Harness;

use Laravel\Ai\Contracts\Tool;

class HarnessRun
{
    /** @param array<string, class-string<Tool>> $tools */
    public function __construct(
        public string $agent,
        public string $instructions,
        public string $prompt,
        public string $model,
        public string $sessionId,
        public string $cwd,
        public PermissionMode $permissionMode,
        public array $tools,
        public int $timeout,
        public string $id,
        public string $harness = 'claude-code',
        public bool $resume = false,
    ) {}
}
