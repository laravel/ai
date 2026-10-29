<?php

namespace Laravel\Ai\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\ClaudeCli\ClaudeCliGateway;

class ClaudeCliProvider extends Provider implements TextProvider
{
    use Concerns\GeneratesText;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    public function __construct(protected array $config, protected Dispatcher $events)
    {
        //
    }

    /**
     * The Claude CLI provider requires no API key — authentication is handled by the CLI itself.
     */
    #[\Override]
    public function providerCredentials(): array
    {
        return [];
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new ClaudeCliGateway($this->events);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'claude-sonnet-5-5';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'claude-haiku-5-5';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'claude-opus-5-5';
    }

    /**
     * Get the path to the Claude CLI binary, defaulting to whatever is on $PATH.
     */
    public function cliBinary(): string
    {
        return $this->config['cli_binary'] ?? 'claude';
    }

    /**
     * Get the configured static MCP server URL, if any.
     */
    public function staticMcpUrl(): ?string
    {
        return $this->config['mcp_url'] ?? null;
    }

    /**
     * Get the configured static MCP servers array, if any.
     *
     * @return array<int, array<string, mixed>>
     */
    public function staticMcpServers(): array
    {
        return $this->config['mcp_servers'] ?? [];
    }
}
