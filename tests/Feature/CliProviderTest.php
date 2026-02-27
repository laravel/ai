<?php

namespace Tests\Feature;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Gateway\Cli\ClaudeCliGateway;
use Laravel\Ai\Gateway\Cli\GeminiCliGateway;
use Laravel\Ai\Providers\CliProvider;
use Tests\TestCase;

class CliProviderTest extends TestCase
{
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = $this->app->make(Dispatcher::class);
    }

    public function test_text_gateway_returns_injected_cli_gateway(): void
    {
        $gw = new ClaudeCliGateway(['timeout' => 10]);
        $provider = new CliProvider($gw, ['name' => 'test', 'driver' => 'claude-cli'], $this->dispatcher);
        $this->assertSame($gw, $provider->textGateway());
    }

    public function test_default_text_model_returns_config_value(): void
    {
        $gw = new ClaudeCliGateway([]);
        $provider = new CliProvider($gw, ['name' => 'x', 'driver' => 'x', 'default_model' => 'opus'], $this->dispatcher);
        $this->assertSame('opus', $provider->defaultTextModel());
    }

    public function test_default_text_model_returns_default_when_no_config(): void
    {
        $gw = new ClaudeCliGateway([]);
        $provider = new CliProvider($gw, ['name' => 'x', 'driver' => 'x'], $this->dispatcher);
        $this->assertSame('default', $provider->defaultTextModel());
    }

    public function test_cheapest_text_model_returns_config_value(): void
    {
        $gw = new ClaudeCliGateway([]);
        $provider = new CliProvider($gw, ['name' => 'x', 'driver' => 'x', 'models' => ['cheapest' => 'haiku']], $this->dispatcher);
        $this->assertSame('haiku', $provider->cheapestTextModel());
    }

    public function test_cheapest_text_model_falls_back_to_default(): void
    {
        $gw = new ClaudeCliGateway([]);
        $provider = new CliProvider($gw, ['name' => 'x', 'driver' => 'x', 'default_model' => 'sonnet'], $this->dispatcher);
        $this->assertSame('sonnet', $provider->cheapestTextModel());
    }

    public function test_smartest_text_model_returns_config_value(): void
    {
        $gw = new ClaudeCliGateway([]);
        $provider = new CliProvider($gw, ['name' => 'x', 'driver' => 'x', 'models' => ['smartest' => 'opus']], $this->dispatcher);
        $this->assertSame('opus', $provider->smartestTextModel());
    }

    public function test_smartest_text_model_falls_back_to_default(): void
    {
        $gw = new ClaudeCliGateway([]);
        $provider = new CliProvider($gw, ['name' => 'x', 'driver' => 'x', 'default_model' => 'sonnet'], $this->dispatcher);
        $this->assertSame('sonnet', $provider->smartestTextModel());
    }

    public function test_use_text_gateway_overrides_injected_cli_gateway(): void
    {
        $original = new ClaudeCliGateway([]);
        $override = new GeminiCliGateway([]);
        $provider = new CliProvider($original, ['name' => 'test', 'driver' => 'test'], $this->dispatcher);

        $this->assertSame($original, $provider->textGateway());
        $provider->useTextGateway($override);
        $this->assertSame($override, $provider->textGateway());
    }

    public function test_cheapest_cascades_to_default_model_to_default(): void
    {
        $gw = new ClaudeCliGateway([]);
        $p1 = new CliProvider($gw, ['name' => 'x', 'driver' => 'x'], $this->dispatcher);
        $this->assertSame('default', $p1->cheapestTextModel());

        $p2 = new CliProvider($gw, ['name' => 'x', 'driver' => 'x', 'default_model' => 'sonnet'], $this->dispatcher);
        $this->assertSame('sonnet', $p2->cheapestTextModel());
    }

    public function test_smartest_cascades_to_default_model_to_default(): void
    {
        $gw = new ClaudeCliGateway([]);
        $p1 = new CliProvider($gw, ['name' => 'x', 'driver' => 'x'], $this->dispatcher);
        $this->assertSame('default', $p1->smartestTextModel());

        $p2 = new CliProvider($gw, ['name' => 'x', 'driver' => 'x', 'default_model' => 'opus'], $this->dispatcher);
        $this->assertSame('opus', $p2->smartestTextModel());
    }

    public function test_all_three_model_methods_independent(): void
    {
        $gw = new ClaudeCliGateway([]);
        $provider = new CliProvider($gw, [
            'name' => 'x', 'driver' => 'x',
            'default_model' => 'sonnet',
            'models' => ['cheapest' => 'haiku', 'smartest' => 'opus'],
        ], $this->dispatcher);
        $this->assertSame('sonnet', $provider->defaultTextModel());
        $this->assertSame('haiku', $provider->cheapestTextModel());
        $this->assertSame('opus', $provider->smartestTextModel());
    }

    public function test_provider_credentials_does_not_crash(): void
    {
        $gw = new ClaudeCliGateway([]);
        $provider = new CliProvider($gw, ['name' => 'test', 'driver' => 'claude-cli'], $this->dispatcher);
        $creds = $provider->providerCredentials();
        $this->assertSame([], $creds);
    }
}
