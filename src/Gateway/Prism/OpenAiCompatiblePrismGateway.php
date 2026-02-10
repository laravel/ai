<?php

namespace Laravel\Ai\Gateway\Prism;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;

class OpenAiCompatiblePrismGateway extends PrismGateway
{
    /**
     * {@inheritdoc}
     */
    protected function createPrismTool(Tool $tool): PrismTool
    {
        $toolName = method_exists($tool, 'name')
            ? $tool->name()
            : class_basename($tool);

        $schema = $tool->schema(new JsonSchemaTypeFactory);

        return (new PrismTool)
            ->as($toolName)
            ->for((string) $tool->description())
            ->when(
                ! empty($schema),
                fn ($prismTool) => $prismTool->withParameter(
                    new SanitizedObjectSchema($schema)
                )
            )
            ->using(fn ($arguments) => $this->invokeTool($tool, $arguments))
            ->withoutErrorHandling();
    }
}
