<?php

use Laravel\Ai\Attributes\ToolSearch;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Promptable;

test('resolves null strategy when the agent has no ToolSearch attribute', function () {
    $agent = new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'x';
        }
    };

    expect(TextGenerationOptions::forAgent($agent)->toolSearchStrategy)->toBeNull();
});

test('resolves regex strategy by default when ToolSearch attribute is present', function () {
    $agent = new #[ToolSearch] class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'x';
        }
    };

    expect(TextGenerationOptions::forAgent($agent)->toolSearchStrategy)->toBe('regex');
});

test('resolves the overridden bm25 strategy', function () {
    $agent = new #[ToolSearch(strategy: 'bm25')] class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'x';
        }
    };

    expect(TextGenerationOptions::forAgent($agent)->toolSearchStrategy)->toBe('bm25');
});
