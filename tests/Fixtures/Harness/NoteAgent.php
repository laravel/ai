<?php

namespace Tests\Fixtures\Harness;

use Laravel\Ai\Harness\HarnessAgent;
use Laravel\Ai\Harness\PermissionMode;

class NoteAgent extends HarnessAgent
{
    public function tools(): iterable
    {
        return [new WriteNote];
    }

    public function permissionMode(): PermissionMode
    {
        return PermissionMode::Default;
    }
}
