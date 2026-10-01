<?php

namespace Laravel\Ai\Tools;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Skills\Skill;
use Stringable;
use Symfony\Component\Finder\SplFileInfo;

class LoadSkill implements Tool
{
    /**
     * The maximum number of bytes that may be read from a bundled file.
     */
    protected const MAX_BYTES = 256 * 1024;

    /**
     * The resolved skills, keyed by name.
     *
     * @var Collection<string, Skill>|null
     */
    protected ?Collection $skills = null;

    /**
     * Create a new skill loading tool instance.
     *
     * @param  list<Closure|Skill|string>  $sources
     */
    public function __construct(protected array $sources = [])
    {
        //
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return $this->skills()
            ->map(fn (Skill $skill): string => "- {$skill->name}: {$skill->description}")
            ->prepend("Load a skill's instructions before performing a task matching the skill's description. Pass a path to read one of the skill's bundled files instead.\n\nAvailable skills:")
            ->implode("\n");
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $name = (string) $request->string('name');

        $skill = $this->skills()->get($name);

        if (! $skill instanceof Skill) {
            return "Skill [{$name}] does not exist.";
        }

        return $request->filled('path')
            ? $this->resource($skill, (string) $request->string('path'))
            : $this->instructions($skill);
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $name = $schema->string()->description('The name of the skill to load.')->required();

        // An empty enum matches no value at all and is rejected outright under strict schemas...
        if (($names = $this->skills()->pluck('name')->values()->all()) !== []) {
            $name->enum($names);
        }

        return [
            'name' => $name,
            'path' => $schema->string()
                ->description("A bundled file path listed by the skill, read instead of the skill's instructions."),
        ];
    }

    /**
     * Get the skill's instructions and the files bundled alongside them.
     */
    protected function instructions(Skill $skill): string
    {
        $files = implode("\n", $this->resources($skill));

        $resources = $files === '' ? '' : <<<EOT
            <skill_resources>
            {$files}
            </skill_resources>
            Read one of these files by calling this tool again with the skill name and the file's path.
            EOT;

        return <<<EOT
            <skill_content name="{$skill->name}">
            {$skill->instructions}{$resources}
            </skill_content>
            EOT;
    }

    /**
     * Read a file bundled with the given skill.
     */
    protected function resource(Skill $skill, string $path): string
    {
        $contents = array_key_exists($path, $skill->files)
            ? (string) $skill->files[$path]
            : $this->read($skill, $path);

        return match (true) {
            $contents === null => "File [{$path}] is not bundled with skill [{$skill->name}].",
            strlen($contents) > static::MAX_BYTES => "File [{$path}] is too large to read inline.",
            ! mb_check_encoding($contents, 'UTF-8') => "File [{$path}] appears to be binary and cannot be read as text.",
            default => $contents,
        };
    }

    /**
     * Read a file from the skill's directory, reading one byte past the limit so oversized files are detected.
     */
    protected function read(Skill $skill, string $path): ?string
    {
        $directory = $skill->path === null ? false : realpath($skill->path);
        $file = $directory === false ? false : realpath($directory.DIRECTORY_SEPARATOR.$path);

        if ($file === false || ! is_file($file) || ! str_starts_with($file, $directory.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return (string) file_get_contents($file, length: static::MAX_BYTES + 1);
    }

    /**
     * Get the paths of the files bundled with the given skill.
     *
     * @return list<string>
     */
    protected function resources(Skill $skill): array
    {
        if ($skill->files !== []) {
            return array_keys($skill->files);
        }

        if ($skill->path === null || ! is_dir($skill->path)) {
            return [];
        }

        return collect(File::allFiles($skill->path))
            ->map(fn (SplFileInfo $file): string => str_replace('\\', '/', $file->getRelativePathname()))
            ->reject(fn (string $path): bool => $path === 'SKILL.md')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Resolve every skill available to the tool, keyed by name.
     *
     * @return Collection<string, Skill>
     */
    protected function skills(): Collection
    {
        return $this->skills ??= collect($this->sources ?: [resource_path('skills')])
            ->flatMap(fn (Closure|Skill|string $source): iterable => match (true) {
                $source instanceof Skill => [$source],
                $source instanceof Closure => collect($source())->values(),
                default => $this->discover($source),
            })
            ->reverse()
            ->keyBy('name')
            ->sortKeys();
    }

    /**
     * Discover the skills stored in the given directory.
     *
     * @return Collection<int, Skill>
     */
    protected function discover(string $directory): Collection
    {
        return collect(glob(rtrim($directory, '/\\').'/*/SKILL.md') ?: [])
            ->map(fn (string $file): ?Skill => Skill::fromDirectory(dirname($file)))
            ->filter();
    }
}
