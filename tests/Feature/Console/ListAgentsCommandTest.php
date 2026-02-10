<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class ListAgentsCommandTest extends TestCase
{
    public function test_list_agents_command_exits_successfully(): void
    {
        $response = $this->artisan('ai:list-agents');

        $response->assertExitCode(0)->run();
    }

    public function test_list_agents_command_displays_agent_info(): void
    {
        $this->artisan('ai:list-agents')
            ->expectsOutputToContain('Agents')
            ->assertExitCode(0)
            ->run();
    }
}
