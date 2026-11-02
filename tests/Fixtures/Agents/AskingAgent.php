<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\ChoiceTool;
use Tests\Fixtures\Tools\ReceiptTool;
use Tests\Fixtures\Tools\SilentGeolocationTool;

class AskingAgent implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You put cards in front of the user.';
    }

    public function tools(): iterable
    {
        return [new ChoiceTool, new ReceiptTool, new SilentGeolocationTool];
    }
}
