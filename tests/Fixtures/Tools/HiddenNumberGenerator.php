<?php

namespace Tests\Fixtures\Tools;

use Laravel\Ai\Attributes\HiddenFromClient;
use Laravel\Ai\Tools\Request;

#[HiddenFromClient]
class HiddenNumberGenerator extends FixedNumberGenerator
{
    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return '48151';
    }
}
