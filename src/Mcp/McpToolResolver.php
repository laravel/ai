<?php

namespace Laravel\Ai\Mcp;

use InvalidArgumentException;

class McpToolResolver
{
    public function __construct(protected McpManager $manager) {}

    /**
     * Resolve MCP tool adapters for the given server references.
     *
     * @param  array<string|ServerReference>  $references
     * @return array<McpTool>
     */
    public function tools(array $references): array
    {
        $resolved = [];

        foreach ($references as $reference) {
            $reference = $this->normalizeReference($reference);
            $server = $this->manager->server($reference->name);
            $tools = $server->tools();

            if (! is_null($reference->only)) {
                $tools = $this->filterTools($server, $tools, $reference->only);
            }

            foreach ($tools as $tool) {
                $resolved[] = new McpTool(
                    $server,
                    $tool,
                    McpToolAlias::make($reference->name, $tool->name),
                );
            }
        }

        return $resolved;
    }

    /**
     * Normalize a string or ServerReference value.
     */
    protected function normalizeReference(string|ServerReference $reference): ServerReference
    {
        return is_string($reference)
            ? new ServerReference($reference)
            : $reference;
    }

    /**
     * Filter tools by an allowlist and fail for missing names.
     *
     * @param  array<Tool>  $tools
     * @param  array<int, string>  $only
     * @return array<Tool>
     */
    protected function filterTools(McpServer $server, array $tools, array $only): array
    {
        $available = collect($tools)->keyBy(fn (Tool $tool) => $tool->name);
        $missing = array_values(array_diff($only, $available->keys()->all()));

        if (filled($missing)) {
            throw new InvalidArgumentException(
                'MCP server ['.$server->name().'] does not expose tool(s) ['.implode(', ', $missing).'].'
            );
        }

        return collect($only)
            ->map(fn (string $name) => $available->get($name))
            ->all();
    }
}
