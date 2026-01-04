<?php

namespace Laravel\Ai\Responses\Data;

abstract class Citation
{
    public function __construct(
        public ?string $title = null,
    ) {}
}
