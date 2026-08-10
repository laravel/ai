<?php

namespace Laravel\Ai\CodeMode;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;

/**
 * The tools code mode exposes, their model-facing signatures, and lookup over both.
 */
class Catalog
{
    /**
     * How many bytes of signatures may be inlined in the execute_code description.
     */
    protected const MAX_INLINE_BYTES = 8000;

    /**
     * The rendered call signature for each path.
     *
     * @var array<string, string>
     */
    protected array $signatures;

    /**
     * @param  array<string, Tool>  $tools
     */
    public function __construct(protected array $tools)
    {
        $this->signatures = [];

        foreach ($tools as $path => $tool) {
            $this->signatures[$path] = $this->signatureFor($path, $tool);
        }
    }

    /**
     * Get the tool registered at the given path.
     */
    public function tool(string $path): ?Tool
    {
        return $this->tools[$path] ?? null;
    }

    /**
     * Get every registered tool path.
     *
     * @return array<int, string>
     */
    public function paths(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Determine whether any tool is left out of the inline catalog.
     */
    public function isPartial(): bool
    {
        return $this->deferred() !== [];
    }

    /**
     * The signatures small enough to inline in the execute_code description.
     *
     * @return array<int, string>
     */
    public function inline(): array
    {
        return array_values(array_slice($this->signatures, 0, $this->inlineCount()));
    }

    /**
     * The paths whose signatures did not fit the inline budget.
     *
     * @return array<int, string>
     */
    public function deferred(): array
    {
        return array_slice($this->paths(), $this->inlineCount());
    }

    /**
     * Rank tools against a query by lexical term overlap, or browse in declaration order when empty.
     *
     * @return array<int, array<string, string>>
     */
    public function search(string $query, int $limit = 10): array
    {
        // A ceiling so an oversized limit cannot pull the whole deferred catalog into context.
        $limit = max(1, min($limit, 50));

        $terms = array_unique(preg_split('/[^a-z0-9]+/', strtolower($query), flags: PREG_SPLIT_NO_EMPTY) ?: []);

        $scores = [];

        foreach ($this->signatures as $path => $signature) {
            $haystack = strtolower($path.' '.$signature);

            $scores[$path] = array_sum(array_map(
                fn (string $term): int => str_contains($haystack, $term) ? 1 : 0, $terms
            ));
        }

        if ($terms !== []) {
            $scores = array_filter($scores);

            // PHP sorts are stable, so equal scores keep declaration order.
            arsort($scores, SORT_NUMERIC);
        }

        return array_map(
            fn (string $path): array => ['path' => $path, 'signature' => $this->signatures[$path]],
            array_slice(array_keys($scores), 0, $limit),
        );
    }

    /**
     * How many signatures fit the inline byte budget.
     */
    protected function inlineCount(): int
    {
        $count = 0;
        $bytes = 0;

        foreach ($this->signatures as $signature) {
            if (($bytes += strlen($signature) + 1) > self::MAX_INLINE_BYTES) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Render a model-facing call signature for a tool.
     */
    protected function signatureFor(string $path, Tool $tool): string
    {
        $schema = (new ObjectSchema((array) $tool->schema(new JsonSchemaTypeFactory)))->toSchema();

        $required = $schema['required'] ?? [];
        $parameters = [];

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $type = $property['type'] ?? 'mixed';
            $type = is_array($type) ? implode('|', $type) : $type;

            $parameter = sprintf("'%s' => %s", $name, $type);

            if (! in_array($name, $required, true)) {
                $parameter .= ' (optional)';
            }

            if (is_string($property['description'] ?? null) && $property['description'] !== '') {
                $parameter .= ' /* '.$property['description'].' */';
            }

            $parameters[] = $parameter;
        }

        return sprintf(
            "tool('%s', [%s]): string — %s",
            $path,
            implode(', ', $parameters),
            (string) $tool->description(),
        );
    }
}
