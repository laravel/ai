<?php

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schedule;
use Laravel\Ai\Files;
use Laravel\Ai\Jobs\InvokeAgent;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\SerializableClosure\SerializableClosure;
use Tests\Fixtures\Agents\AssistantAgent;

function fakeAgentResponse(string $text): AgentResponse
{
    return new AgentResponse('inv-1', $text, new Usage, new Meta);
}

it('registers the agent schedule macro', function () {
    expect(Schedule::hasMacro('agent'))->toBeTrue();
});

it('queues the agent job when the scheduled event runs', function () {
    Bus::fake();

    $pending = Schedule::agent(AssistantAgent::class, 'Summarize today\'s signups')->daily();

    expect($pending->expression)->toBe('0 0 * * *');
    expect($pending->description)->toBe('agent:AssistantAgent');

    $pending->run(app());

    Bus::assertDispatched(InvokeAgent::class, fn ($job) => $job->agent instanceof AssistantAgent
        && $job->prompt === 'Summarize today\'s signups'
    );
});

it('passes attachments, provider, and model through to the queued job', function () {
    Bus::fake();

    $attachment = Files\Document::fromPath('/home/laravel/transcript.md');

    Schedule::agent(new AssistantAgent, 'Analyze the transcript', [$attachment], model: 'claude-haiku-4-5-20251001')
        ->hourly()
        ->run(app());

    Bus::assertDispatched(InvokeAgent::class, fn ($job) => $job->attachments === [$attachment]
        && $job->model === 'claude-haiku-4-5-20251001'
    );
});

it('proxies scheduler methods and keeps chaining on the wrapper', function () {
    $pending = Schedule::agent(AssistantAgent::class, 'Digest');

    expect($pending->weekdays()->dailyAt('08:00'))->toBe($pending);
    expect($pending->expression)->toBe('0 8 * * 1-5');
});

it('writes the agent output to a file via sendOutputTo', function () {
    $file = tempnam(sys_get_temp_dir(), 'agent-output');

    $pending = Schedule::agent(AssistantAgent::class, 'Summarize')->daily()->sendOutputTo($file);

    ($pending->thenCallbacks()[0])(fakeAgentResponse('Daily digest summary'));

    expect(trim(file_get_contents($file)))->toBe('Daily digest summary');

    unlink($file);
});

it('appends the agent output via appendOutputTo', function () {
    $file = tempnam(sys_get_temp_dir(), 'agent-output');

    $pending = Schedule::agent(AssistantAgent::class, 'Summarize')->daily()->appendOutputTo($file);

    ($pending->thenCallbacks()[0])(fakeAgentResponse('First run'));
    ($pending->thenCallbacks()[0])(fakeAgentResponse('Second run'));

    expect(file_get_contents($file))->toBe("First run\nSecond run\n");

    unlink($file);
});

it('emails the agent output via emailOutputTo', function () {
    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('raw')->once()->with('Daily digest summary', Mockery::type('Closure'));
    app()->instance(Mailer::class, $mailer);

    $pending = Schedule::agent(AssistantAgent::class, 'Summarize')->daily()->emailOutputTo('taylor@example.com');

    ($pending->thenCallbacks()[0])(fakeAgentResponse('Daily digest summary'));
});

it('does not email empty output by default', function () {
    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldNotReceive('raw');
    app()->instance(Mailer::class, $mailer);

    $pending = Schedule::agent(AssistantAgent::class, 'Summarize')->daily()->emailOutputTo('taylor@example.com');

    ($pending->thenCallbacks()[0])(fakeAgentResponse('   '));
});

it('registers reporting callbacks that survive queue serialization', function () {
    $file = tempnam(sys_get_temp_dir(), 'agent-output');

    $pending = Schedule::agent(AssistantAgent::class, 'Summarize')->daily()->sendOutputTo($file);

    $callback = unserialize(serialize(new SerializableClosure($pending->thenCallbacks()[0])))->getClosure();

    $callback(fakeAgentResponse('Serialized digest'));

    expect(trim(file_get_contents($file)))->toBe('Serialized digest');

    unlink($file);
});

it('emails on failure via emailOutputOnFailure', function () {
    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('raw')->once()->with('Agent exploded', Mockery::type('Closure'));
    app()->instance(Mailer::class, $mailer);

    $pending = Schedule::agent(AssistantAgent::class, 'Summarize')->daily()->emailOutputOnFailure('ops@example.com');

    ($pending->catchCallbacks()[0])(new RuntimeException('Agent exploded'));
});
