<?php

namespace Tests\Fixtures\Tools;

use Laravel\Ai\Attributes\WithoutStreaming;
use Laravel\Ai\Tools\Request;

#[WithoutStreaming]
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
