<?php

namespace Laravel\Ai\Enums;

enum MessageStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case Paused = 'paused';
}
