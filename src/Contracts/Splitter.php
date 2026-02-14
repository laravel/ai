<?php

declare(strict_types=1);

namespace Laravel\Ai\Contracts;

interface Splitter
{
    /**
     * Split a text payload into chunks.
     *
     * @return array<int, string>
     */
    public function split(string $text): array;
}
