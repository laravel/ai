<?php

namespace Laravel\Ai\Tools;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;

/**
 * The searchable catalog of tools and schemas behind a tool search.
 */
class ToolCatalog
{
    protected const MAX_SCHEMA_BYTES = 16000;

    protected const MAX_SEARCH_RESULTS = 50;

    protected const MAX_SEARCH_BYTES = 32000;

    /**
     * @var array<string, array{name: string, group?: string, description: string, schema: array<string, mixed>}>
     */
    protected array $entries = [];

    /**
     * @param  array<string, Tool>  $tools
     * @param  array<string, string>  $groups
     */
    public function __construct(protected array $tools, protected array $groups = [])
    {
        foreach ($tools as $name => $tool) {
            $schema = (new ObjectSchema((array) $tool->schema(new JsonSchemaTypeFactory)))->toSchema();
            $encodedSchema = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            if (strlen($encodedSchema) > self::MAX_SCHEMA_BYTES) {
                throw new InvalidArgumentException(sprintf(
                    'The schema for tool search tool [%s] exceeds the %d byte catalog limit.', $name, self::MAX_SCHEMA_BYTES
                ));
            }

            $this->entries[$name] = array_filter([
                'name' => $name,
                'group' => $groups[$name] ?? null,
                'description' => (string) $tool->description(),
                'schema' => $schema,
            ], fn (mixed $value): bool => $value !== null);
        }
    }

    public function tool(string $name): ?Tool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Get the unique group names in the catalog, in insertion order.
     *
     * @return array<int, string>
     */
    public function groups(): array
    {
        return array_values(array_unique(array_column($this->entries, 'group')));
    }

    /**
     * @return array<int, array{name: string, description: string, schema: array<string, mixed>}>
     */
    public function search(string $query, int $limit = 10): array
    {
        $limit = max(1, min($limit, self::MAX_SEARCH_RESULTS));
        $terms = $this->terms($query);
        $scores = [];

        foreach ($this->entries as $name => $entry) {
            $fields = [
                [Str::lower($name), 8],
                [Str::lower($entry['group'] ?? ''), 8],
                [Str::lower($entry['description']), 4],
                [Str::lower($this->schemaSearchText($entry['schema'])), 2],
            ];

            $score = 0;

            // Whole-token matches outrank substring fallbacks, which keep unsegmented (CJK) text searchable...
            foreach ($fields as [$text, $weight]) {
                $tokens = $this->terms($text);

                foreach ($terms as $term) {
                    $score += in_array($term, $tokens, true)
                        ? $weight
                        : (str_contains($text, $term) ? intdiv($weight, 2) : 0);
                }
            }

            $scores[$name] = $score;
        }

        if ($terms !== []) {
            $scores = array_filter($scores);
            arsort($scores, SORT_NUMERIC);
        }

        $results = [];
        $bytes = 2;

        foreach (array_slice(array_keys($scores), 0, $limit) as $name) {
            $entry = $this->entries[$name];
            $entryBytes = strlen(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) + 1;

            if ($bytes + $entryBytes > self::MAX_SEARCH_BYTES) {
                break;
            }

            $results[] = $entry;
            $bytes += $entryBytes;
        }

        return $results;
    }

    /** @return array<int, string> */
    protected function terms(string $value): array
    {
        return array_values(array_unique(
            preg_split('/[^\p{L}\p{N}]+/u', Str::lower($value), flags: PREG_SPLIT_NO_EMPTY) ?: []
        ));
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function schemaSearchText(array $schema): string
    {
        $parts = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, ['properties', 'description', 'enum', 'const', 'title'], true)) {
                $parts[] = is_scalar($value) ? (string) $value : $this->schemaValueText($value);

                continue;
            }

            if (is_array($value)) {
                $parts[] = $this->schemaValueText($value);
            }
        }

        return implode(' ', $parts);
    }

    protected function schemaValueText(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return '';
        }

        $parts = [];

        foreach ($value as $key => $nested) {
            if (is_string($key)) {
                $parts[] = $key;
            }

            $parts[] = $this->schemaValueText($nested);
        }

        return implode(' ', $parts);
    }
}
