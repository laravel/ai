<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\InteractiveChoiceTool;
use Tests\Fixtures\Tools\PresentableReceiptTool;
use Tests\Fixtures\Tools\PresentedChoiceTool;

class SurfaceAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You put cards in front of the user.';
    }

    public function tools(): iterable
    {
        return [new InteractiveChoiceTool, new PresentableReceiptTool, new PresentedChoiceTool];
    }
}
