<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\RecoverFromToolFailures;

#[RecoverFromToolFailures]
class RecoveringThrowingToolAgent extends ThrowingToolAgent
{
    //
}
