<?php

namespace Laravel\Ai\Gateway\Cli;

use Closure;
use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\CliProcessException;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

abstract class CliGateway implements TextGateway
{
    protected ?Closure $invokingToolCallback = null;

    protected ?Closure $toolInvokedCallback = null;

    /**
     * The session IDs tracked for conversation continuity.
     *
     * @var array<string, string>
     */
    protected array $sessions = [];

    public function __construct(
        protected array $config,
    ) {}

    /**
     * {@inheritdoc}
     */
    abstract public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse;

    /**
     * {@inheritdoc}
     */
    abstract public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator;

    /**
     * {@inheritdoc}
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;

        return $this;
    }

    /**
     * Run a CLI process and return its stdout output.
     *
     * @param  string[]  $command
     *
     * @throws CliProcessException
     */
    protected function runProcess(array $command, ?string $stdin = null, ?int $timeout = null): string
    {
        $process = new Process(
            $command,
            null,
            $this->environment(),
            $stdin,
            $timeout ?? $this->config['timeout'] ?? 300,
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new CliProcessException(
                'CLI process timed out after '.$process->getTimeout().' seconds.',
                previous: $e,
            );
        }

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            throw new CliProcessException(
                $stderr ?: 'CLI process exited with code '.$process->getExitCode().'.',
            );
        }

        return $process->getOutput();
    }

    /**
     * Start a CLI process for streaming and return the process instance.
     *
     * @param  string[]  $command
     */
    protected function startProcess(array $command, ?string $stdin = null, ?int $timeout = null): Process
    {
        $process = new Process(
            $command,
            null,
            $this->environment(),
            $stdin,
            $timeout ?? $this->config['timeout'] ?? 300,
        );

        $process->start();

        return $process;
    }

    /**
     * Get the environment variables for the CLI process.
     *
     * @return array<string, string>
     */
    protected function environment(): array
    {
        $env = array_merge(getenv(), $this->config['env'] ?? []);

        // Remove nesting-protection variables so CLI tools can be
        // spawned from within other CLI tool sessions (e.g. Claude Code).
        unset($env['CLAUDECODE'], $env['CLAUDE_CODE_ENTRYPOINT'], $env['CLAUDE_CODE_SESSION']);

        return $env;
    }

    /**
     * Determine if the given messages represent a continued conversation.
     *
     * @param  \Laravel\Ai\Messages\Message[]  $messages
     */
    protected function isContinuation(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the last user message from the messages array.
     *
     * @param  \Laravel\Ai\Messages\Message[]  $messages
     */
    protected function lastUserMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof UserMessage) {
                return $messages[$i]->content;
            }
        }

        return '';
    }

    /**
     * Format all messages into a single prompt string.
     *
     * @param  \Laravel\Ai\Messages\Message[]  $messages
     */
    protected function formatAllMessages(?string $instructions, array $messages): string
    {
        $parts = [];

        if ($instructions) {
            $parts[] = $instructions;
        }

        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $parts[] = $message->content;
            } elseif ($message instanceof AssistantMessage) {
                $parts[] = $message->content;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Generate a conversation key for session tracking.
     *
     * @param  \Laravel\Ai\Messages\Message[]  $messages
     */
    protected function conversationKey(?string $instructions, array $messages): string
    {
        $firstUserContent = '';

        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $firstUserContent = $message->content;
                break;
            }
        }

        return md5(($instructions ?? '').$firstUserContent);
    }

    /**
     * Store a session ID for a conversation.
     */
    protected function storeSession(string $conversationKey, string $sessionId): void
    {
        $this->sessions[$conversationKey] = $sessionId;
    }

    /**
     * Retrieve a stored session ID for a conversation.
     */
    protected function getSession(string $conversationKey): ?string
    {
        return $this->sessions[$conversationKey] ?? null;
    }

    /**
     * Create a new TextResponse with the given text and provider metadata.
     */
    protected function makeTextResponse(string $text, TextProvider $provider, string $model): TextResponse
    {
        return new TextResponse(
            $text,
            new Usage(0, 0),
            new Meta($provider->name(), $model),
        );
    }
}
