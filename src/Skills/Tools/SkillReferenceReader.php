<?php

namespace Laravel\Ai\Skills\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Stringable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Skills\SkillRegistry;
use Laravel\Ai\Tools\Request;

class SkillReferenceReader implements Tool
{
    /**
     * Create a new tool instance.
     */
    public function __construct(
        protected SkillRegistry $registry
    ) {}

    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return 'skill_read';
    }

    /**
     * Get the description of the tool.
     */
    public function description(): Stringable|string
    {
        return 'Reads a file from a skill\'s directory.';
    }

    /**
     * Run the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $skillName = (string) $request->string('skill');
        $fileName = (string) $request->string('file');

        $skill = $this->registry->get($skillName);

        if (! $skill) {
            return sprintf("Skill '%s' not loaded or not found.", $skillName);
        }

        if (! $skill->basePath) {
            return sprintf("Skill '%s' does not have a base path.", $skillName);
        }

        // Security check: block absolute path attempts
        if (str_starts_with($fileName, '/')) {
            return 'Access denied: Cannot read outside skill directory.';
        }

        $filePath = $skill->basePath.'/'.$fileName;

        if (! file_exists($filePath)) {
            return sprintf("File '%s' not found in skill directory.", $fileName);
        }

        // Whitelist check: Ensure file is in allowed reference files
        if (! in_array($fileName, $skill->referenceFiles(), true)) {
            return sprintf("File '%s' is not in the allowed reference files list.", $fileName);
        }

        $path = realpath($filePath);
        $basePath = realpath($skill->basePath);

        // Double-check resolved path stays within skill directory (with trailing slash)
        if (! $path || ! $basePath) {
            return 'Access denied: Cannot read outside skill directory.';
        }

        $basePath = rtrim($basePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_starts_with($path, $basePath)) {
            return 'Access denied: Cannot read outside skill directory.';
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return sprintf("Failed to read file '%s'.", $fileName);
        }

        return $content;
    }

    /**
     * Get the parameter schema for the tool.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'skill' => $schema->string()
                ->description('The name of the skill')
                ->required(),
            'file' => $schema->string()
                ->description('The relative path to the file within the skill directory')
                ->required(),
        ];
    }
}
