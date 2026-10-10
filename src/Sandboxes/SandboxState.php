<?php

namespace Laravel\Ai\Sandboxes;

enum SandboxState: string
{
    case Creating = 'creating';
    case Running = 'running';
    case Stopped = 'stopped';
    case Error = 'error';
    case Terminated = 'terminated';
}
