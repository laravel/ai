<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Presentable;
use Laravel\Ai\Contracts\Surface;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\Surfaces\ChoiceCard;

class InteractiveChoiceTool implements Approvable, Presentable, Tool
{
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Asks the user to choose.';
    }

    public function present(Request $request): Surface
    {
        return new ChoiceCard($request['question'], $request['options']);
    }

    public function handle(Request $request): string
    {
        return 'chose: '.$request['answer'];
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
