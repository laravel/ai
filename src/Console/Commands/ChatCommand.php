<?php

namespace Laravel\Ai\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function Laravel\Ai\agent;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\textarea;

#[AsCommand(name: 'agent:chat')]
class ChatCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:chat
        {prompt? : Prompt to send (skips interactive mode).}
        {--agent= : Fully-qualified agent class to use (resolved from the container).}
        {--instructions= : Instructions for an ad-hoc agent (ignored when using --agent).}
        {--provider= : Provider name override (e.g. openai, anthropic).}
        {--model= : Model override.}
        {--stream : Stream the response instead of waiting for the full output.}
        {--show-usage : Display usage information after each response.}
        {--show-meta : Display provider/model information after each response.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Chat with one of your agents';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $provider = $this->option('provider') ?: null;
        $model = $this->option('model') ?: null;

        [$makeAgent, $clearConversation, $rememberResponse] = $this->resolveAgentFactory();

        $prompt = $this->argument('prompt');

        if (is_string($prompt) && trim($prompt) !== '') {
            return $this->runSinglePrompt($makeAgent(), $clearConversation, $rememberResponse, $prompt, $provider, $model);
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('No prompt provided. Pass a prompt argument or run interactively.');

            return self::FAILURE;
        }

        note(implode(PHP_EOL, [
            'Starting chat session.',
            '',
            'Commands:',
            '  /help  Show this help',
            '  /exit  Exit the chat',
            '  /clear Clear the current conversation (ad-hoc agents only)',
        ]));

        while (true) {
            $prompt = textarea('Prompt...');

            $prompt = trim((string) $prompt);

            if ($prompt === '') {
                continue;
            }

            if (in_array(strtolower($prompt), ['/exit', '/quit', 'exit', 'quit'], true)) {
                return self::SUCCESS;
            }

            if (strtolower($prompt) === '/help') {
                note(implode(PHP_EOL, [
                    'Commands:',
                    '  /help  Show this help',
                    '  /exit  Exit the chat',
                    '  /clear Clear the current conversation (ad-hoc agents only)',
                ]));

                continue;
            }

            if (strtolower($prompt) === '/clear') {
                if ($clearConversation()) {
                    note('Conversation cleared.');
                } else {
                    $this->components->warn('This agent does not support clearing the conversation.');
                }

                continue;
            }

            $this->runPrompt($makeAgent(), $rememberResponse, $prompt, $provider, $model);
        }
    }

    /**
     * @return array{0: callable(): Agent, 1: callable(): bool, 2: callable(mixed): void}
     */
    protected function resolveAgentFactory(): array
    {
        $agentClass = $this->option('agent');

        if (is_string($agentClass) && trim($agentClass) !== '') {
            $agentClass = ltrim(trim($agentClass), '\\');

            $agent = $this->laravel->make($agentClass);

            if (! $agent instanceof Agent) {
                $this->components->error("The [{$agentClass}] class is not a valid AI agent.");

                return [fn () => agent(instructions: 'You are a helpful assistant.'), fn () => false, fn () => null];
            }

            return [fn () => $agent, fn () => false, fn () => null];
        }

        $instructions = (string) ($this->option('instructions') ?? 'You are a helpful assistant.');
        $messages = [];

        $makeAgent = function () use ($instructions, &$messages): Agent {
            return agent(instructions: $instructions, messages: $messages);
        };

        $clear = function () use (&$messages): bool {
            $messages = [];

            return true;
        };

        $remember = function (mixed $response) use (&$messages): void {
            // Some response types (like structured output) may not include message history.
            if (is_object($response) && isset($response->messages) && method_exists($response->messages, 'all')) {
                /** @var array $newMessages */
                $newMessages = $response->messages->all();

                if (count($newMessages) > 0) {
                    $messages = $newMessages;
                }
            }
        };

        return [$makeAgent, $clear, $remember];
    }

    protected function runSinglePrompt(Agent $agent, callable $clearConversation, callable $rememberResponse, string $prompt, ?string $provider, ?string $model): int
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            $this->components->error('Prompt cannot be empty.');

            return self::FAILURE;
        }

        if (strtolower($prompt) === '/clear') {
            $clearConversation();

            return self::SUCCESS;
        }

        if (in_array(strtolower($prompt), ['/exit', '/quit', 'exit', 'quit'], true)) {
            return self::SUCCESS;
        }

        return $this->runPrompt($agent, $rememberResponse, $prompt, $provider, $model) ? self::SUCCESS : self::FAILURE;
    }

    protected function runPrompt(Agent $agent, callable $rememberResponse, string $prompt, ?string $provider, ?string $model): bool
    {
        try {
            if ($this->option('stream')) {
                $response = $agent->stream($prompt, provider: $provider, model: $model);

                $this->output->writeln('');

                $response->each(function ($event) {
                    if ($event instanceof TextDelta) {
                        $this->output->write($event->delta);
                    }
                });

                $this->output->writeln(PHP_EOL);

                if ($this->option('show-meta')) {
                    $this->components->info('Meta: '.json_encode([
                        'provider' => $provider ?? '(default)',
                        'model' => $model ?? '(default)',
                    ]));
                }

                if ($this->option('show-usage') && $response->usage) {
                    $this->components->info('Usage: '.json_encode($response->usage->toArray()));
                }

                return true;
            }

            $response = spin(
                fn () => $agent->prompt($prompt, provider: $provider, model: $model),
                message: 'Thinking...',
            );

            $rememberResponse($response);

            note($this->formatResponseForDisplay($response));

            if ($this->option('show-meta')) {
                $this->components->info('Meta: '.json_encode([
                    'provider' => $response->meta->provider,
                    'model' => $response->meta->model,
                ]));
            }

            if ($this->option('show-usage')) {
                $this->components->info('Usage: '.json_encode($response->usage->toArray()));
            }

            return true;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return false;
        }
    }

    protected function formatResponseForDisplay(mixed $response): string
    {
        if ($response instanceof StructuredAgentResponse) {
            $json = json_encode(
                $response->structured,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            return $json === false ? (string) $response : $json;
        }

        return (string) $response;
    }
}
