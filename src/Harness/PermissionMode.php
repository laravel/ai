<?php

namespace Laravel\Ai\Harness;

enum PermissionMode: string
{
    case BypassPermissions = 'bypassPermissions';
    case AcceptEdits = 'acceptEdits';
    case Default = 'default';
}
