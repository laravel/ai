<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Concerns\CanBeConcurrent;
use Laravel\Ai\Tools\Request;
use Spatie\Fork\Fork;

test('concurrent tools run the model\'s tool calls concurrently', function () {
    requiresApiKey('OPENAI_API_KEY');

    if (! extension_loaded('pcntl') || ! class_exists(Fork::class)) {
        test()->markTestSkipped('The fork concurrency driver requires the pcntl extension and spatie/fork (CLI only).');
    }

    config(['concurrency.default' => 'fork']);

    $prompt = 'Fetch both the sales report and the inventory report, then reply with a one sentence summary.';

    $parallel = timeReportAgent(new ParallelReportAgent, $prompt);

    if ($parallel['batched'] < 2) {
        test()->markTestSkipped('The model did not emit both tool calls in a single step, so there was nothing to parallelize.');
    }

    $sequential = timeReportAgent(new SequentialReportAgent, $prompt);

    expect($parallel['tools'])->toBe(2)
        ->and($sequential['tools'])->toBe(2)
        ->and($parallel['seconds'])->toBeLessThan($sequential['seconds']);
})->group('integration');

function timeReportAgent(Agent $agent, string $prompt): array
{
    $start = microtime(true);

    $response = $agent->prompt($prompt, provider: 'openai', model: 'gpt-5.4-nano');

    return [
        'seconds' => microtime(true) - $start,
        'tools' => collect($response->steps)->sum(fn ($step) => count($step->toolResults)),
        'batched' => count($response->steps->first()->toolResults),
    ];
}

class SlowReportTool implements Tool
{
    use CanBeConcurrent;

    public function __construct(
        public string $toolName,
        public string $report,
    ) {
        $this->concurrent();
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return "Fetch the current {$this->report}. This calls a slow external service and takes a few seconds to respond.";
    }

    public function handle(Request $request): string
    {
        sleep(3);

        return "The {$this->report} is ready.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

class SequentialSlowReportTool extends SlowReportTool
{
    public function __construct(
        string $toolName,
        string $report,
    ) {
        parent::__construct($toolName, $report);
        $this->concurrent(false);
    }

    public function handle(Request $request): string
    {
        return parent::handle($request);
    }
}

abstract class ReportAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a reporting assistant. Each tool fetches one slow report. When the user asks for more than one report, call every relevant tool in the same turn so they run together; never call them one at a time, and do not ask clarifying questions.';
    }

    protected function reportTools(): array
    {
        return [
            new SlowReportTool('fetch_sales_report', 'sales report'),
            new SlowReportTool('fetch_inventory_report', 'inventory report'),
        ];
    }
}

class ParallelReportAgent extends ReportAgent
{
    public function tools(): array
    {
        return $this->reportTools();
    }
}

class SequentialReportAgent extends ReportAgent
{
    public function tools(): array
    {
        return [
            new SequentialSlowReportTool('fetch_sales_report', 'sales report'),
            new SequentialSlowReportTool('fetch_inventory_report', 'inventory report'),
        ];
    }
}
