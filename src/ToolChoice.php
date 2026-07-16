<?php

namespace Laravel\Ai;

use Attribute;
use InvalidArgumentException;

/**
 * Controls whether and which tool a model must call, mapped to each provider's native tool_choice field.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ToolChoice
{
    public const string auto = 'auto';

    public const string none = 'none';

    public const string required = 'required';

    public const string tool = 'tool';

    public function __construct(
        public readonly string $mode,
        public readonly ?string $toolName = null,
    ) {
        if (! in_array($mode, [self::auto, self::none, self::required, self::tool], true)) {
            throw new InvalidArgumentException("Unrecognized tool choice mode \"{$mode}\".");
        }

        if ($mode === self::tool && ($toolName === null || $toolName === '')) {
            throw new InvalidArgumentException('Tool choice mode "tool" requires a tool name.');
        }

        if ($mode !== self::tool && $toolName !== null) {
            throw new InvalidArgumentException('Tool choice "toolName" is only valid for mode "tool".');
        }
    }

    /**
     * Require the model to call the tool with the given name.
     */
    public static function tool(string $name): self
    {
        return new self(self::tool, $name);
    }

    /**
     * Coerce a ToolChoice, a mode string, or a ['tool' => 'name'] array into a ToolChoice instance.
     *
     * @param  self|string|array<string, mixed>  $value
     */
    public static function from(self|string|array $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            return new self($value);
        }

        foreach (['toolName', 'tool', 'name'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                return self::tool($value[$key]);
            }
        }

        throw new InvalidArgumentException('Unrecognized tool choice value.');
    }
}
