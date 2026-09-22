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
     * The maximum number of bundled files listed for a skill.
     */
    protected const MAX_RESOURCES = 50;

    /**
     * The resolved skills, keyed by name.
     */
    protected ?Collection $skills = null;

    /**
     * Create a new skill loading tool instance.
     *
     * @param  list<Closure|Skill|string>  $sources
     */
    public function __construct(protected array $sources = []) {}

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
        if (($names = $this->skills()->keys()->all()) !== []) {
            $name->enum($names);
        }

        return [
            'name' => $name,
            'path' => $schema->string()
                ->description("A bundled file path listed by the skill, read instead of the skill's instructions."),
        ];
    }

    /**
     * Determine whether any skills were discovered.
     */
    public function isEmpty(): bool
    {
        return $this->skills()->isEmpty();
    }

    /**
     * Get the skill's instructions and the files bundled alongside them.
     */
    protected function instructions(Skill $skill): string
    {
        $sections = ["<skill_content name=\"{$skill->name}\">", (string) $skill->instructions];

        if (($resources = $this->resources($skill)) !== []) {
            $sections[] = "<skill_resources>\n".implode("\n", $resources)."\n</skill_resources>\n"
                ."Read one of these files by calling this tool again with the skill name and the file's path.";
        }

        $sections[] = '</skill_content>';

        return implode("\n\n", $sections);
    }

    /**
     * Read a file bundled with the given skill.
     */
    protected function resource(Skill $skill, string $path): string
    {
        if (array_key_exists($path, $skill->files)) {
            return (string) $skill->files[$path];
        }

        $directory = $skill->path === null ? false : realpath($skill->path);
        $file = $directory === false ? false : realpath($directory.DIRECTORY_SEPARATOR.$path);

        // Both ends are resolved before comparison so a traversing path cannot escape the skill's own directory...
        if ($file === false || ! is_file($file) || ! str_starts_with($file, $directory.DIRECTORY_SEPARATOR)) {
            return "File [{$path}] is not bundled with skill [{$skill->name}].";
        }

        return (string) file_get_contents($file);
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
            ->take(static::MAX_RESOURCES)
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
                $source instanceof Closure => $source(),
                default => $this->discover($source),
            })
            // Reversed so the earliest source wins a name collision, then sorted so the prompt stays cacheable...
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
