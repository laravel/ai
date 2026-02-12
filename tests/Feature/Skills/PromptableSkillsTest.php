<?php

namespace Tests\Feature\Skills;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Stringable;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Skillable;
use Laravel\Ai\Skills\SkillMode;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Tools\Request;
use Mockery;
use Tests\TestCase;

class PromptableSkillsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_appends_skill_instructions_when_prompting()
    {
        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('load')->with('test-skill', SkillMode::Full)->once();
        $registry->shouldReceive('instructions')->with(null)->andReturn('Skill instructions');

        $this->app->instance(SkillRegistry::class, $registry);

        $agent = new class implements Agent
        {
            use Promptable, Skillable;

            public function skills(): iterable
            {
                return ['test-skill' => SkillMode::Full];
            }

            public function instructions(): Stringable|string
            {
                return 'Base instructions';
            }
        };

        $agent::fake(['response']);

        $agent->prompt('hello');

        $agent::assertPrompted(function ($prompt) {
            return str_contains($prompt->instructions, 'Base instructions')
                && str_contains($prompt->instructions, 'Skill instructions');
        });
    }

    public function test_get_tools_returns_tools_when_agent_defines_them()
    {
        $toolA = new class implements Tool
        {
            public function name(): string
            {
                return 'tool_a';
            }

            public function description(): string
            {
                return 'Tool A';
            }

            public function handle(Request $request): string
            {
                return 'result';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };
        $toolB = new class implements Tool
        {
            public function name(): string
            {
                return 'tool_b';
            }

            public function description(): string
            {
                return 'Tool B';
            }

            public function handle(Request $request): string
            {
                return 'result';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $agent = new class($toolA, $toolB) implements Agent
        {
            use Promptable;

            private object $toolA;

            private object $toolB;

            public function __construct(object $toolA, object $toolB)
            {
                $this->toolA = $toolA;
                $this->toolB = $toolB;
            }

            public function tools(): array
            {
                return [$this->toolA, $this->toolB];
            }

            public function instructions(): string
            {
                return 'Test instructions';
            }
        };

        $agent::fake(['response']);

        $agent->prompt('hello');

        $agent::assertPrompted(function ($prompt) use ($toolA, $toolB) {
            return $prompt->tools === [$toolA, $toolB];
        });
    }

    public function test_get_tools_returns_empty_array_when_agent_has_no_tools()
    {
        $agent = new class implements Agent
        {
            use Promptable;

            public function instructions(): string
            {
                return 'No tools agent';
            }
        };

        $agent::fake(['response']);

        $agent->prompt('hello');

        $agent::assertPrompted(function ($prompt) {
            return $prompt->tools === [];
        });
    }

    public function test_get_tools_merges_skill_tools_with_agent_tools()
    {
        $registry = Mockery::mock(SkillRegistry::class);
        $registry->shouldReceive('load')->once();
        $registry->shouldReceive('instructions')->with(null)->andReturn('');

        $this->app->instance(SkillRegistry::class, $registry);

        $agent = new class implements Agent
        {
            use Promptable, Skillable;

            public function skills(): iterable
            {
                return ['test-skill' => SkillMode::Full];
            }

            public function tools(): iterable
            {
                return [];
            }

            public function instructions(): string
            {
                return 'Test';
            }
        };

        $agent::fake(['response']);

        $agent->prompt('hello');

        $agent::assertPrompted(function ($prompt) {
            $toolNames = array_map(fn ($t) => $t->name(), $prompt->tools);

            return in_array('skill_list', $toolNames)
                && in_array('skill_load', $toolNames)
                && in_array('skill_read', $toolNames);
        });
    }
}
