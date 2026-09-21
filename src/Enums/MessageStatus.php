<?php

namespace Laravel\Ai\Enums;

enum MessageStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case Paused = 'paused';
    case Started = 'started';

    /**
     * Determine whether the turn stopped before the run returned a response.
     */
    public function isInterrupted(): bool
    {
        return $this === self::Started || $this === self::Failed;
    }
}
