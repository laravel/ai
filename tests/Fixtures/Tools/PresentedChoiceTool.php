<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Presentable;
use Laravel\Ai\Contracts\Surface;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Surfaces\ChoiceCard;

class PresentedChoiceTool implements Presentable, Tool
{
    public function description(): string
    {
        return 'Shows the choices without asking for one.';
    }

    public function present(Request $request): Surface
    {
        return new ChoiceCard($request['question'], $request['options']);
    }

    public function handle(Request $request): string
    {
        return 'Shown.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
