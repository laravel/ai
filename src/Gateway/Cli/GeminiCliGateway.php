<?php

namespace Laravel\Ai\Gateway\Cli;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\CliProcessException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class GeminiCliGateway extends CliGateway
{
    /**
     * {@inheritdoc}
     */
    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        // Gemini CLI has no session management in headless mode,
        // so we always format all messages into a single prompt.
        $prompt = $this->formatAllMessages($instructions, $messages);

        $command = $this->buildCommand($model, $prompt);

        $output = $this->runProcess($command, null, $timeout);

        $parsed = $this->parseJsonOutput($output);

        $text = $parsed['response'] ?? $output;

        return $this->makeTextResponse($text, $provider, $model);
    }

    /**
     * {@inheritdoc}
     */
    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        $prompt = $this->formatAllMessages($instructions, $messages);
        $command = $this->buildCommand($model, $prompt);

        $process = $this->startProcess($command, null, $timeout);

        $messageId = (string) Str::uuid7();

        try {
            while ($process->isRunning()) {
                $process->checkTimeout();

                $incremental = $process->getIncrementalOutput();

                if ($incremental === '') {
                    usleep(10000);

                    continue;
                }

                yield new TextDelta(
                    id: (string) Str::uuid7(),
                    messageId: $messageId,
                    delta: $incremental,
                    timestamp: time(),
                );
            }
        } catch (ProcessTimedOutException $e) {
            throw new CliProcessException(
                'Gemini CLI streaming timed out after '.$process->getTimeout().' seconds.',
                previous: $e,
            );
        }

        // Capture any remaining output.
        $remaining = $process->getIncrementalOutput();

        if ($remaining !== '') {
            yield new TextDelta(
                id: (string) Str::uuid7(),
                messageId: $messageId,
                delta: $remaining,
                timestamp: time(),
            );
        }

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            throw new CliProcessException(
                $stderr ?: 'Gemini CLI stream process exited with code '.$process->getExitCode().'.',
            );
        }

        yield new StreamEnd(
            id: (string) Str::uuid7(),
            reason: 'end',
            usage: new Usage,
            timestamp: time(),
        );
    }

    /**
     * Build the command for a Gemini CLI request.
     *
     * @return string[]
     */
    protected function buildCommand(string $model, string $prompt = ''): array
    {
        $command = [$this->binary(), '--prompt', $prompt];

        if ($model) {
            $command[] = '--model';
            $command[] = $model;
        }

        return $command;
    }

    /**
     * Parse the JSON output from Gemini CLI.
     *
     * @return array<string, mixed>
     */
    protected function parseJsonOutput(string $output): array
    {
        $decoded = json_decode(trim($output), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return ['response' => trim($output)];
        }

        return $decoded;
    }

    /**
     * Get the path to the Gemini CLI binary.
     */
    protected function binary(): string
    {
        return $this->config['binary'] ?? 'gemini';
    }
}
