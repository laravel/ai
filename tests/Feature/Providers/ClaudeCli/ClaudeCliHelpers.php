<?php

namespace Tests\Feature\Providers\ClaudeCli;

use Laravel\Ai\AiManager;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Tests\Fixtures\Agents\AssistantAgent;

trait ClaudeCliHelpers
{
    /**
     * Write a temporary fake `claude` binary that outputs the given JSONL lines on stdout.
     *
     * Returns the path to the script so callers can pass it as `cli_binary` in the provider config.
     * The JSONL content is written to a sidecar file to avoid shell-escaping issues.
     */
    protected function fakeCli(array $lines): string
    {
        $jsonl = implode("\n", array_map('json_encode', $lines))."\n";

        $dataFile = sys_get_temp_dir().'/fake-claude-data-'.uniqid();
        file_put_contents($dataFile, $jsonl);

        $script = "#!/bin/bash\ncat ".escapeshellarg($dataFile)."\n";

        $path = sys_get_temp_dir().'/fake-claude-'.uniqid();
        file_put_contents($path, $script);
        chmod($path, 0755);

        return $path;
    }

    /**
     * Write a fake `claude` binary that records all arguments to a file and then outputs JSONL.
     *
     * Returns [$scriptPath, $argsFilePath].
     */
    protected function fakeCliCapturingArgs(array $lines): array
    {
        $jsonl = implode("\n", array_map('json_encode', $lines))."\n";

        $dataFile = sys_get_temp_dir().'/fake-claude-data-'.uniqid();
        file_put_contents($dataFile, $jsonl);

        $argsFile = sys_get_temp_dir().'/fake-claude-args-'.uniqid();

        $script = "#!/bin/bash\necho \"\$*\" > ".escapeshellarg($argsFile)."\ncat ".escapeshellarg($dataFile)."\n";

        $path = sys_get_temp_dir().'/fake-claude-'.uniqid();
        file_put_contents($path, $script);
        chmod($path, 0755);

        return [$path, $argsFile];
    }

    /**
     * Collect all streaming events from an agent using the Claude CLI provider.
     *
     * @return StreamEvent[]
     */
    protected function collectStreamEvents(?object $agent = null, string $provider = 'claude-cli'): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream('Hello', provider: $provider);

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Build the JSONL sequence that the Claude CLI emits for a successful run.
     */
    protected function cliSuccessLines(string $text = 'Hello', string $sessionId = 'sess-abc123', string $model = 'claude-sonnet-5-5'): array
    {
        return [
            [
                'type' => 'system',
                'subtype' => 'init',
                'session_id' => $sessionId,
                'tools' => [],
                'mcp_servers' => [],
            ],
            [
                'type' => 'assistant',
                'message' => [
                    'id' => 'msg_01',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => $model,
                    'content' => [
                        ['type' => 'text', 'text' => $text],
                    ],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ],
            [
                'type' => 'result',
                'subtype' => 'success',
                'result' => $text,
                'session_id' => $sessionId,
            ],
        ];
    }

    /**
     * Build the JSONL sequence the CLI emits for an error result.
     */
    protected function cliErrorLines(string $error = 'Something went wrong', string $sessionId = 'sess-abc123'): array
    {
        return [
            [
                'type' => 'system',
                'subtype' => 'init',
                'session_id' => $sessionId,
                'tools' => [],
                'mcp_servers' => [],
            ],
            [
                'type' => 'result',
                'subtype' => 'error',
                'error' => $error,
                'session_id' => $sessionId,
            ],
        ];
    }

    /**
     * Override the claude-cli provider's cli_binary in the application config
     * and flush the cached driver so the next call picks up the new binary.
     */
    protected function useCliBinary(string $path): void
    {
        config(['ai.providers.claude-cli.cli_binary' => $path]);
        app(AiManager::class)->forgetInstance('claude-cli');
    }

    /**
     * Tear down any temporary fake CLI scripts and data files created during tests.
     */
    protected function cleanUpFakeCliScripts(): void
    {
        foreach (glob(sys_get_temp_dir().'/fake-claude-*') as $file) {
            @unlink($file);
        }
    }
}
