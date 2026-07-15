<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Exceptions\ApprovalNotResumableException;
use Laravel\Ai\Responses\Data\UrlCitation;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Citation;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Prompts\Prompt;
use ReflectionMethod;
use RuntimeException;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\stream;
use function Laravel\Prompts\table;
use function Laravel\Prompts\textarea;
use function Laravel\Prompts\title;
use function Laravel\Prompts\warning;

class AgentCommand extends Command
{
    /**
     * The visibility modes shared by the tool and reasoning renderers.
     *
     * @var array<int, string>
     */
    private const VISIBILITY_MODES = ['full', 'collapsed', 'auto-collapsed', 'hidden'];

    /**
     * The character length above which auto-collapsed content is summarized instead of shown in full.
     */
    private const AUTO_COLLAPSE_THRESHOLD = 200;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent
        {AgentName : An agent class or its name from Workbench\\App\\Agents}
        {--title= : The title shown in the terminal}
        {--tools=auto-collapsed : full, collapsed, auto-collapsed, or hidden}
        {--reasoning=auto-collapsed : full, collapsed, auto-collapsed, or hidden}
        {--response-statistics=output-tokens-per-second : output-tokens-per-second or output-token-count}
        {--context-size= : The model context window, in tokens}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a Laravel AI SDK agent in an interactive terminal UI';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $agent = $this->resolveAgent((string) $this->argument('AgentName'));
            $options = $this->options();

            $this->validateOption('tools', $options['tools'], self::VISIBILITY_MODES);
            $this->validateOption('reasoning', $options['reasoning'], self::VISIBILITY_MODES);
            $this->validateOption('response-statistics', $options['response-statistics'], ['output-tokens-per-second', 'output-token-count']);

            $contextSize = $this->contextSize($options['context-size']);
        } catch (InvalidArgumentException $exception) {
            error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->input->isInteractive() || ! stream_isatty(STDIN)) {
            error('The agent terminal UI requires an interactive TTY.');

            return self::FAILURE;
        }

        $title = filled($options['title'])
            ? $options['title']
            : class_basename($agent);

        $this->startConversation($agent);

        Prompt::cancelUsing(fn () => throw new AgentTuiInterrupted);

        try {
            $this->renderWelcome($title, $agent);

            while (true) {
                $prompt = textarea(
                    label: 'Prompt',
                    placeholder: 'Ask '.$title.' anything...',
                    hint: 'Ctrl+D submits · /help for controls · /exit closes the session',
                    rows: 3,
                );

                $command = $this->handleCommand($prompt, $title, $agent);

                if ($command === 'exit') {
                    break;
                }

                if ($command === 'handled' || blank($prompt)) {
                    continue;
                }

                $this->streamUntilComplete(
                    $agent,
                    $prompt,
                    tools: $options['tools'],
                    reasoning: $options['reasoning'],
                    responseStatistics: $options['response-statistics'],
                    contextSize: $contextSize,
                );
            }
        } catch (AgentTuiInterrupted) {
            // Ctrl+C is an expected way to leave an interactive terminal session.
        } finally {
            Prompt::cancelUsing(null);
        }

        outro('Session closed.');

        return self::SUCCESS;
    }

    /**
     * Resolve an agent from either its fully-qualified class name or workbench alias.
     */
    private function resolveAgent(string $name): Agent
    {
        $class = ltrim(str_replace('/', '\\', $name), '\\');

        if (! str_contains($class, '\\')) {
            $class = 'Workbench\\App\\Agents\\'.$class;
        }

        if (! class_exists($class)) {
            throw new InvalidArgumentException("Agent [{$name}] was not found.");
        }

        $agent = app($class);

        if (! $agent instanceof Agent) {
            throw new InvalidArgumentException("[{$class}] must implement ".Agent::class.'.');
        }

        return $agent;
    }

    /**
     * Start one in-memory terminal session for agents that remember conversations.
     */
    private function startConversation(Agent $agent): void
    {
        if ($this->remembersConversations($agent)) {
            $this->beginConversation($agent);

            return;
        }

        if ($agent instanceof HasTools) {
            warning('This agent does not remember conversations. Tool approvals cannot be resumed until it uses RemembersConversations.');
        }
    }

    /**
     * Continue an agent turn until it has no more pending tool approvals.
     */
    private function streamUntilComplete(
        Agent $agent,
        string $prompt,
        string $tools,
        string $reasoning,
        string $responseStatistics,
        ?int $contextSize,
    ): void {
        $nextPrompt = $prompt;

        while (true) {
            try {
                $response = $agent->stream($nextPrompt);

                $pendingApprovals = $this->renderResponse(
                    $response,
                    tools: $tools,
                    reasoning: $reasoning,
                    responseStatistics: $responseStatistics,
                    contextSize: $contextSize,
                );
            } catch (ApprovalNotResumableException $exception) {
                error($exception->getMessage());

                return;
            }

            if ($pendingApprovals->isEmpty()) {
                return;
            }

            $nextPrompt = $this->requestApprovals($pendingApprovals);
        }
    }

    /**
     * Collect a decision for every pending approval, offering a bulk shortcut when several are queued.
     *
     * @param  Collection<int, PendingApproval>  $pendingApprovals
     * @return array<string, Decision>
     */
    private function requestApprovals(Collection $pendingApprovals): array
    {
        if ($pendingApprovals->count() > 1 && ($bulk = $this->requestBulkDecision($pendingApprovals)) !== null) {
            return $bulk;
        }

        return $pendingApprovals
            ->mapWithKeys(fn (PendingApproval $approval) => [
                $approval->id => $this->requestApproval($approval),
            ])
            ->all();
    }

    /**
     * Offer a single approve-all/reject-all decision for a batch of pending approvals.
     *
     * Returns null when the user chooses to decide each approval individually.
     *
     * @param  Collection<int, PendingApproval>  $pendingApprovals
     * @return array<string, Decision>|null
     */
    private function requestBulkDecision(Collection $pendingApprovals): ?array
    {
        $tools = $pendingApprovals
            ->map(fn (PendingApproval $approval) => $approval->tool)
            ->implode(', ');

        note("{$pendingApprovals->count()} tool approvals required · {$tools}");

        return match (select(
            label: 'How would you like to respond?',
            options: [
                'each' => 'Decide each individually',
                'approve-all' => 'Approve all',
                'reject-all' => 'Reject all',
            ],
            default: 'each',
        )) {
            'approve-all' => Decision::approveAll(),
            'reject-all' => Decision::rejectAll('Rejected in terminal UI.'),
            default => null,
        };
    }

    /**
     * Render every event from a streamed response and return any approvals it requests.
     *
     * @return Collection<int, PendingApproval>
     */
    private function renderResponse(
        StreamableAgentResponse $response,
        string $tools,
        string $reasoning,
        string $responseStatistics,
        ?int $contextSize,
    ): Collection {
        $startedAt = hrtime(true);
        $firstTokenAt = null;
        $pendingApprovals = collect();
        $reasoningParts = [];
        $textStream = null;

        foreach ($response as $event) {
            if ($event instanceof TextDelta || $event instanceof ReasoningDelta) {
                $firstTokenAt ??= hrtime(true);
            }

            if ($event instanceof TextDelta) {
                $textStream ??= stream();
                $textStream->append($this->renderMarkdownDelta($event->delta));

                continue;
            }

            $textStream?->close();
            $textStream = null;

            match (true) {
                $event instanceof ToolCall => $this->renderToolCall($event, $tools),
                $event instanceof ToolResult => $this->renderToolResult($event, $tools),
                $event instanceof ToolApprovalRequest => $pendingApprovals = $pendingApprovals
                    ->merge($event->pendingApprovals)
                    ->values(),
                $event instanceof ReasoningStart => $reasoningParts[$event->reasoningId] = '',
                $event instanceof ReasoningDelta => $reasoningParts[$event->reasoningId] = ($reasoningParts[$event->reasoningId] ?? '').$event->delta,
                $event instanceof ReasoningEnd => $this->renderReasoning(
                    $reasoningParts[$event->reasoningId] ?? '',
                    $reasoning,
                ),
                $event instanceof Citation => $this->renderCitation($event),
                $event instanceof ProviderToolEvent => $this->renderProviderToolEvent($event, $tools),
                $event instanceof Error => error($event->message),
                $event instanceof StreamEnd => null,
                default => null,
            };
        }

        $textStream?->close();

        $this->renderResponseStatistics(
            $response,
            // Measure throughput from the first token so time-to-first-token is excluded.
            seconds: (hrtime(true) - ($firstTokenAt ?? $startedAt)) / 1_000_000_000,
            mode: $responseStatistics,
            contextSize: $contextSize,
        );

        return $pendingApprovals;
    }

    /**
     * Render a tool call in the selected visibility mode.
     */
    private function renderToolCall(ToolCall $event, string $mode): void
    {
        if ($mode === 'hidden') {
            return;
        }

        $tool = $event->toolCall;
        $input = $this->formatValue($tool->arguments);

        if ($this->effectiveMode($mode, $input) === 'collapsed') {
            info("Tool · {$tool->name}");

            return;
        }

        table(['Tool', 'Input'], [[
            $tool->name,
            $input,
        ]]);
    }

    /**
     * Render a completed or failed tool call in the selected visibility mode.
     */
    private function renderToolResult(ToolResult $event, string $mode): void
    {
        if ($mode === 'hidden') {
            return;
        }

        $result = $event->toolResult;
        $status = $event->successful ? 'completed' : 'failed';
        $output = $event->successful ? $this->formatValue($result->result) : ($event->error ?? 'The tool call failed.');

        if ($this->effectiveMode($mode, $output) === 'collapsed') {
            info("Tool {$status} · {$result->name}");

            return;
        }

        table(['Tool', 'Status', 'Output'], [[
            $result->name,
            $status,
            $output,
        ]]);
    }

    /**
     * Render a provider-native tool event that does not map to an SDK tool call.
     */
    private function renderProviderToolEvent(ProviderToolEvent $event, string $mode): void
    {
        if ($mode === 'hidden') {
            return;
        }

        $data = $this->formatValue($event->data);

        if ($this->effectiveMode($mode, $data) === 'collapsed') {
            info("Provider tool · {$event->type} ({$event->status})");

            return;
        }

        table(['Provider tool', 'Status', 'Data'], [[
            $event->type,
            $event->status,
            $data,
        ]]);
    }

    /**
     * Render model reasoning without ever exposing it when the user selected hidden mode.
     */
    private function renderReasoning(string $text, string $mode): void
    {
        if ($mode === 'hidden' || blank($text)) {
            return;
        }

        if ($this->effectiveMode($mode, $text) === 'collapsed') {
            info('Reasoning · '.mb_strlen($text).' characters');

            return;
        }

        note($this->renderMarkdownBlock($text), 'info');
    }

    /**
     * Resolve the auto-collapsed mode to full or collapsed based on the content length.
     */
    private function effectiveMode(string $mode, string $content): string
    {
        if ($mode !== 'auto-collapsed') {
            return $mode;
        }

        return mb_strlen($content) > self::AUTO_COLLAPSE_THRESHOLD ? 'collapsed' : 'full';
    }

    /**
     * Render URL citations emitted by providers that support them.
     */
    private function renderCitation(Citation $event): void
    {
        if (! $event->citation instanceof UrlCitation) {
            return;
        }

        $label = $event->citation->title ?? $event->citation->url;

        info("Source · {$label}\n{$event->citation->url}");
    }

    /**
     * Ask for a yes/no decision using the same y/n controls as the upstream TUI.
     */
    private function requestApproval(PendingApproval $approval): Decision
    {
        note("Tool approval required · {$approval->tool}\n".$this->formatValue($approval->arguments));

        $approved = confirm(
            label: "Allow {$approval->tool}?",
            default: false,
            yes: 'Approve',
            no: 'Reject',
            hint: $approval->reason ?? 'This tool requires your approval.',
        );

        return $approved ? Decision::approve() : Decision::reject('Rejected in terminal UI.');
    }

    /**
     * Render output token count or throughput and optional context window use.
     */
    private function renderResponseStatistics(
        StreamableAgentResponse $response,
        float $seconds,
        string $mode,
        ?int $contextSize,
    ): void {
        $usage = $response->usage;

        // A zeroed usage is reported for turns that end without real usage, such as errors.
        if ($usage === null || ($usage->promptTokens === 0 && $usage->completionTokens === 0)) {
            return;
        }

        $output = $usage->completionTokens;
        $message = $mode === 'output-token-count'
            ? "Output · {$output} tokens"
            : 'Output · '.number_format($output / max($seconds, 0.001), 1).' tokens/s';

        if ($contextSize !== null) {
            $total = $usage->promptTokens + $usage->completionTokens;
            $message .= ' · Context '.number_format(($total / $contextSize) * 100, 1)."% ({$total}/{$contextSize})";
        }

        info($message);
    }

    /**
     * Handle slash commands without sending them to the model.
     */
    private function handleCommand(string $prompt, string $title, Agent $agent): ?string
    {
        return match (trim($prompt)) {
            '/exit', '/quit' => 'exit',
            '/clear' => $this->clearAndRenderWelcome($title, $agent),
            '/help' => $this->renderHelp(),
            '/new', '/reset' => $this->resetConversation($agent),
            default => null,
        };
    }

    private function clearAndRenderWelcome(string $title, Agent $agent): string
    {
        clear();
        $this->renderWelcome($title, $agent);

        return 'handled';
    }

    private function renderHelp(): string
    {
        note("Controls\nCtrl+D · submit a multi-line prompt\ny / n · approve or reject a tool\nApprove all / Reject all · decide a batch of approvals at once\nCtrl+C · exit\n/clear · repaint the terminal\n/new · start a fresh conversation\n/exit · close the session");

        return 'handled';
    }

    private function resetConversation(Agent $agent): string
    {
        if (! $this->remembersConversations($agent)) {
            warning('This agent does not persist conversations.');

            return 'handled';
        }

        $this->beginConversation($agent);
        info('Started a fresh conversation.');

        return 'handled';
    }

    private function renderWelcome(string $title, Agent $agent): void
    {
        title($title);
        intro("Laravel AI SDK · {$title}");
        info('Streaming responses, Markdown, tool activity, approvals, reasoning, citations, and response statistics are enabled.');

        if ($agent instanceof HasTools) {
            info('Tool calls are shown as cards. Approvals default to reject.');
        }
    }

    private function remembersConversations(Agent $agent): bool
    {
        return $agent instanceof Conversational
            && in_array(RemembersConversations::class, class_uses_recursive($agent), true);
    }

    private function beginConversation(Agent $agent): void
    {
        (new ReflectionMethod($agent, 'forUser'))->invoke($agent, (object) ['id' => 0]);
    }

    private function renderMarkdownDelta(string $text): string
    {
        $text = str_replace("\r", '', $text);

        $text = preg_replace_callback('/`([^`]+)`/', fn (array $matches) => "\e[36m{$matches[1]}\e[39m", $text) ?? $text;
        $text = preg_replace_callback('/\\*\\*([^*]+)\\*\\*/', fn (array $matches) => "\e[1m{$matches[1]}\e[22m", $text) ?? $text;

        return preg_replace('/(^|\\n)(#{1,6}\\s+)([^\\n]+)/', "$1\e[1;36m$3\e[22;39m", $text) ?? $text;
    }

    /**
     * Format completed Markdown blocks such as reasoning with the same styles.
     */
    private function renderMarkdownBlock(string $text): string
    {
        return $this->renderMarkdownDelta($text);
    }

    private function formatValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: get_debug_type($value);
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function validateOption(string $name, mixed $value, array $allowed): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("The --{$name} option must be one of: ".implode(', ', $allowed).'.');
        }
    }

    private function contextSize(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException('The --context-size option must be a positive integer.');
        }

        return (int) $value;
    }
}

class AgentTuiInterrupted extends RuntimeException
{
    //
}
