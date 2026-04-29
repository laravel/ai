<?php

namespace Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Tests\Fixtures\Tools\FileMutatingTool;

class FileAttachmentToolAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(private string $path) {}

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function tools(): iterable
    {
        return [new FileMutatingTool($this->path)];
    }
}
