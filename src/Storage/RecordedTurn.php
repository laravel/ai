<?php

namespace Laravel\Ai\Storage;

class RecordedTurn
{
    public function __construct(
        public readonly ?string $invocationId,
        public readonly string $assistantMessageId,
        public readonly ?string $userMessageId = null,
        public readonly bool $startedConversation = false,
    ) {}
}
