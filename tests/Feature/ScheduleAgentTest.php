<?php

use Illuminate\Support\Facades\Schedule;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Fixtures\Agents\AssistantAgent;

it('registers the agent schedule macro', function () {
    expect(Schedule::hasMacro('agent'))->toBeTrue();
});

it('runs an agent and writes its response to the output', function () {
    AssistantAgent::fake(['Daily digest summary']);

    $this->artisan('agent:run', [
        'agent' => AssistantAgent::class,
        'prompt' => 'Summarize today\'s signups',
    ])->assertSuccessful()->expectsOutput('Daily digest summary');

    AssistantAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === 'Summarize today\'s signups');
});

it('passes provider, model, and timeout options to the agent', function () {
    AssistantAgent::fake(['Digest']);

    $this->artisan('agent:run', [
        'agent' => AssistantAgent::class,
        'prompt' => 'Summarize',
        '--provider' => 'openai',
        '--model' => 'claude-haiku-4-5-20251001',
        '--timeout' => '120',
    ])->assertSuccessful();

    AssistantAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->model === 'claude-haiku-4-5-20251001'
        && $prompt->timeout === 120
    );
});

it('fails when the agent class is invalid', function () {
    $this->artisan('agent:run', ['agent' => 'App\\Nope'])->assertFailed();
});

it('fails with a non-zero exit code when the agent throws', function () {
    AssistantAgent::fake([fn () => throw new RuntimeException('Agent exploded')]);

    $this->artisan('agent:run', [
        'agent' => AssistantAgent::class,
        'prompt' => 'Summarize',
    ])->assertFailed();
});

it('schedules the agent as an agent:run command', function () {
    $event = Schedule::agent(AssistantAgent::class, 'Summarize signups')->daily();

    expect($event->command)->toContain('agent:run')
        ->and($event->command)->toContain('AssistantAgent')
        ->and($event->command)->toContain('Summarize signups')
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->description)->toBe('agent:AssistantAgent');
});

it('includes provider, model, and timeout in the scheduled command', function () {
    $event = Schedule::agent(AssistantAgent::class, 'Summarize', provider: 'anthropic', model: 'claude-x', timeout: 120)->daily();

    expect($event->command)->toContain('--provider=')
        ->and($event->command)->toContain('anthropic')
        ->and($event->command)->toContain('--model=')
        ->and($event->command)->toContain('claude-x')
        ->and($event->command)->toContain('--timeout=120');
});

it('supports the native scheduler output methods', function () {
    $file = sys_get_temp_dir().'/agent-output.log';

    $event = Schedule::agent(AssistantAgent::class, 'Summarize')
        ->daily()
        ->sendOutputTo($file)
        ->emailOutputTo('taylor@example.com');

    expect($event->output)->toBe($file);
});
