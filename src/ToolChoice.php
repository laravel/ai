<?php

namespace Laravel\Ai;

use InvalidArgumentException;
use Laravel\Ai\Enums\ToolChoiceMode;

/**
 * Controls whether and which tool a model must call, mapped to each provider's
 * native tool_choice field: auto (default), none, required (any tool), or a named tool.
 */
final class ToolChoice
{
    public function __construct(
        public readonly ToolChoiceMode $mode,
        public readonly ?string $toolName = null,
    ) {
        if ($mode === ToolChoiceMode::Tool && ($toolName === null || $toolName === '')) {
            throw new InvalidArgumentException('Tool choice mode "tool" requires a tool name.');
        }

        if ($mode !== ToolChoiceMode::Tool && $toolName !== null) {
            throw new InvalidArgumentException('Tool choice "toolName" is only valid for mode "tool".');
        }
    }

    /**
     * Let the model decide whether to call a tool (the provider default).
     */
    public static function auto(): self
    {
        return new self(ToolChoiceMode::Auto);
    }

    /**
     * Prevent the model from calling any tool.
     */
    public static function none(): self
    {
        return new self(ToolChoiceMode::None);
    }

    /**
     * Require the model to call one of the available tools.
     */
    public static function required(): self
    {
        return new self(ToolChoiceMode::Required);
    }

    /**
     * Require the model to call the tool with the given name.
     */
    public static function tool(string $name): self
    {
        return new self(ToolChoiceMode::Tool, $name);
    }

    /**
     * Coerce a flexible value into a ToolChoice instance.
     *
     * Accepts a ToolChoice, a ToolChoiceMode, the strings "auto"|"none"|"required",
     * or an array selecting a tool: ['tool' => 'name'], ['toolName' => 'name'], or ['name' => 'name'].
     *
     * @param  self|ToolChoiceMode|string|array<string, mixed>  $value
     */
    public static function from(self|ToolChoiceMode|string|array $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof ToolChoiceMode) {
            return new self($value);
        }

        if (is_string($value)) {
            return new self(ToolChoiceMode::from($value));
        }

        foreach (['toolName', 'tool', 'name'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                return self::tool($value[$key]);
            }
        }

        throw new InvalidArgumentException('Unrecognized tool choice value.');
    }
}
