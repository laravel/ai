<?php

namespace Tests\Feature\Skills;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Stringable;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Skillable;
use Laravel\Ai\Skills\SkillMode;
use Tests\TestCase;

class TestSkillAgent implements Agent
{
    use Promptable;
    use Skillable;

    public function skills(): iterable
    {
        return ['test-skill' => SkillMode::Full];
    }

    public function tools(): iterable
    {
        return [];
    }

    public function instructions(): Stringable|string
    {
        return 'Base instructions';
    }
}

class IntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        $path = resource_path('skills/test-skill');
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }

        parent::tearDown();
    }

    public function test_end_to_end_skill_flow()
    {
        $path = resource_path('skills/test-skill');
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }

        // 1. Create a new skill using the artisan command
        $this->artisan('make:skill', ['name' => 'test-skill'])
            ->assertExitCode(0);

        // Verify the file exists
        $skillPath = resource_path('skills/test-skill/SKILL.md');
        $this->assertFileExists($skillPath);

        // Customize the skill content for testing
        $skillContent = <<<'EOT'
---
name: test-skill
description: A test skill for integration testing
---
You are a helpful test assistant.
Always respond with "Test Successful".
EOT;
        File::put($skillPath, $skillContent);

        // 2. Fake the AI response
        TestSkillAgent::fake([
            'Test Successful',
        ]);

        // 3. Instantiate the concrete agent class
        $agent = new TestSkillAgent;

        // 4. Prompt the agent
        $response = $agent->prompt('Check for test-skill');

        // 5. Verify the output
        $this->assertEquals('Test Successful', $response->text);

        // 6. Verify the skill was injected correctly
        TestSkillAgent::assertPrompted(function ($prompt) {
            // Check that instructions contain the skill instructions
            $instructions = (string) $prompt->instructions;

            // The skill content "You are a helpful test assistant." should be present if the skill was loaded.
            return str_contains($instructions, 'You are a helpful test assistant');
        });

    }
}
