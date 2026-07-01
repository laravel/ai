<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;
use Laravel\Ai\Files;
use Laravel\Ai\Jobs\InvokeAgent;
use Tests\Fixtures\Agents\AssistantAgent;

it('registers the agent schedule macro', function () {
    expect(Schedule::hasMacro('agent'))->toBeTrue();
});

it('dispatches the agent job when the scheduled event runs', function () {
    Bus::fake();

    $event = Schedule::agent(AssistantAgent::class, 'Summarize today\'s signups')->daily();

    expect($event->expression)->toBe('0 0 * * *');
    expect($event->description)->toBe('agent:AssistantAgent');

    $event->run(app());

    Bus::assertDispatched(InvokeAgent::class, fn ($job) => $job->agent instanceof AssistantAgent
        && $job->prompt === 'Summarize today\'s signups'
    );
});

it('passes attachments through to the queued agent job', function () {
    Bus::fake();

    $attachment = Files\Document::fromPath('/home/laravel/transcript.md');

    Schedule::agent(AssistantAgent::class, 'Analyze the attached transcript', [$attachment])
        ->daily()
        ->run(app());

    Bus::assertDispatched(InvokeAgent::class, fn ($job) => $job->attachments === [$attachment]);
});

it('accepts provider/model overrides and an already-constructed agent', function () {
    Bus::fake();

    Schedule::agent(new AssistantAgent, model: 'claude-haiku-4-5-20251001')->hourly()->run(app());

    Bus::assertDispatched(InvokeAgent::class, fn ($job) => $job->model === 'claude-haiku-4-5-20251001');
});
