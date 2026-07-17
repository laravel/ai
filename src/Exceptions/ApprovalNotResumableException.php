<?php

namespace Laravel\Ai\Exceptions;

class ApprovalNotResumableException extends AiException
{
    /**
     * Create a new approval not resumable exception.
     */
    public static function make(): self
    {
        return new self('Tool approval requires a conversational agent so pending tool calls can be resumed from history.');
    }
}
