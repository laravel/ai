<?php

namespace Laravel\Ai\Events;

class DeferredToolQueued
{
    public function __construct(
        public string $toolClass,
        public array $arguments,
        public string $toolCallId,
    ) {}
}
