<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Support\Collection;

trait JoinsReasoning
{
    /**
     * Join reasoning blocks, separating each block with a blank line.
     *
     * @param  iterable<int, string>  $blocks
     */
    protected function joinReasoning(iterable $blocks): string
    {
        return Collection::wrap($blocks)
            ->filter(fn (string $block): bool => trim($block) !== '')
            ->implode("\n\n");
    }
}
