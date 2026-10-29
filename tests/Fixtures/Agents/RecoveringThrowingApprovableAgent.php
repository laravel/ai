<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Attributes\RecoverFromToolFailures;

#[RecoverFromToolFailures]
class RecoveringThrowingApprovableAgent extends RememberingThrowingApprovableAgent
{
    //
}
