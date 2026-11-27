<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\ApprovableNumberGenerator;
use Tests\Fixtures\Tools\FixedNumberGenerator;

uses(RefreshDatabase::class)->beforeEach(function (): void {
    $this->artisan('migrate', [
        '--path' => __DIR__.'/../../database/migrations',
        '--realpath' => true,
    ])->run();
})->in(__FILE__);

test('a second resume replays a mixed batch so the thinking signed after the first resume still verifies', function (): void {
    requiresApiKey('ANTHROPIC_API_KEY');

    Config::set('ai.conversations.generate_title', false);
    Config::set('ai.providers.anthropic.anthropic_beta', 'thinking-binding-controls-2026-08-01');

    $agent = fn () => new class implements Agent, Conversational, HasProviderOptions, HasTools
    {
        use Promptable;
        use RemembersConversations;

        public function instructions(): string
        {
            return 'In your first response, call ApprovableNumberGenerator and FixedNumberGenerator together, ApprovableNumberGenerator first. '
                .'Once you have both results, call ApprovableNumberGenerator exactly once more, alone. Then reply with one short sentence listing the numbers. '
                .'Before every tool call, think carefully about whether the numbers so far look cryptographically plausible.';
        }

        public function tools(): iterable
        {
            return [new ApprovableNumberGenerator, new FixedNumberGenerator];
        }

        public function providerOptions(Lab|string $provider): array
        {
            return [
                'thinking' => ['type' => 'adaptive', 'block_binding' => ['prefix_mismatch_behavior' => 'error']],
                'output_config' => ['effort' => 'max'],
            ];
        }
    };

    $user = (object) ['id' => 1];

    $paused = $agent()->forUser($user)->prompt('Generate the numbers.', provider: 'anthropic', model: 'claude-sonnet-5-5');

    expect($paused->toolCalls->pluck('name')->all())->toBe(['ApprovableNumberGenerator', 'FixedNumberGenerator']);

    $pausedAgain = $agent()->continue($paused->conversationId, $user)
        ->prompt(Decisions::from([$paused->pendingApprovals[0]->id => true]), provider: 'anthropic', model: 'claude-sonnet-5-5');

    expect($pausedAgain->hasPendingApprovals())->toBeTrue();

    $resumed = $agent()->continue($paused->conversationId, $user)
        ->prompt(Decisions::from([$pausedAgain->pendingApprovals[0]->id => true]), provider: 'anthropic', model: 'claude-sonnet-5-5');

    expect($resumed->hasPendingApprovals())->toBeFalse()
        ->and($resumed->text)->not->toBeEmpty();
});
