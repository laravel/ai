<?php

namespace Laravel\Ai\Skills;

use Illuminate\Support\Facades\File;
use Stringable;

class Skill implements Stringable
{
    /**
     * Create a new skill instance.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $content,
        public readonly string $path,
        public readonly ?string $license = null,
        public readonly ?string $compatibility = null,
        public readonly ?string $allowedTools = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * Get the relative paths of all resource files in the skill directory.
     *
     * @return array<string>
     */
    public function resources(): array
    {
        $resources = [];

        foreach (['scripts', 'references', 'assets'] as $directory) {
            $directoryPath = $this->path.'/'.$directory;

            if (! File::isDirectory($directoryPath)) {
                continue;
            }

            foreach (File::allFiles($directoryPath) as $file) {
                $resources[] = $directory.'/'.$file->getRelativePathname();
            }
        }

        sort($resources);

        return $resources;
    }

    /**
     * Read the contents of a resource file.
     */
    public function resource(string $relativePath): ?string
    {
        $fullPath = $this->path.'/'.$relativePath;

        if (! File::exists($fullPath) || ! str_starts_with(realpath($fullPath), realpath($this->path))) {
            return null;
        }

        return File::get($fullPath);
    }

    /**
     * Get the skill properties as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'description' => $this->description,
            'license' => $this->license,
            'compatibility' => $this->compatibility,
            'allowed_tools' => $this->allowedTools,
            'metadata' => ! empty($this->metadata) ? $this->metadata : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Get the skill XML representation for agent prompts.
     */
    public function toXml(): string
    {
        $xml = "<skill>\n";
        $xml .= "<name>\n{$this->name}\n</name>\n";
        $xml .= "<description>\n{$this->description}\n</description>\n";
        $xml .= "</skill>";

        return $xml;
    }

    /**
     * Get the string representation of the skill.
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
