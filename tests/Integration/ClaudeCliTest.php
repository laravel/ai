<?php

use Laravel\Ai\Promptable;
use Laravel\Ai\Contracts\Agent;

/**
 * Skip if the `claude` CLI binary is not on $PATH or not at the configured location.
 */
function requiresClaudeCli(): void
{
    $binary = config('ai.providers.claude-cli.cli_binary', 'claude');

    $found = is_executable($binary)
        ? $binary
        : trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));

    if (empty($found)) {
        test()->markTestSkipped('Claude CLI binary not found — skipping end-to-end test.');
    }
}

test('claude cli executes bash tool and returns output in final text', function (): void {
    requiresClaudeCli();

    // Embed a sentinel value the CLI must discover via a bash tool call.
    // The CLI handles Bash execution internally across multiple turns; the
    // framework only sees the final text response.
    $sentinel = 'laravel_ai_cli_test_' . substr(md5((string) time()), 0, 8);

    $agent = new class ($sentinel) implements Agent
    {
        use Promptable;

        public function __construct(private readonly string $sentinel) {}

        public function instructions(): string
        {
            return 'You are a helpful assistant. When asked to run a bash command, use the Bash tool to execute it, then include the exact output in your reply.';
        }
    };

    $response = $agent->prompt(
        "Run this bash command and include the exact output in your reply: echo {$sentinel}",
        provider: 'claude-cli',
    );

    // The gateway accumulates all text across multiple assistant turns.
    // The sentinel must appear in the final response, proving that:
    //   (a) the CLI completed at least one Bash tool call internally, and
    //   (b) the framework received and surfaced the resulting text.
    expect($response->text)->not->toBeEmpty()
        ->and($response->text)->toContain($sentinel);

    // Provider meta is always present
    expect($response->meta->provider)->toBe('claude-cli')
        ->and($response->meta->model)->not->toBeEmpty();
});
