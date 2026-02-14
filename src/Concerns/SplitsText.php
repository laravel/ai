<?php

declare(strict_types=1);

namespace Laravel\Ai\Concerns;

use Laravel\Ai\Contracts\Splitter;
use Laravel\Ai\Splitters\RecursiveCharacterTextSplitter;
use RuntimeException;

trait SplitsText
{
    /**
     * Split a document's text content using the default recursive character splitter.
     *
     * @param  array<int, string>  $separators
     * @return array<int, string>
     */
    public function splitText(
        int $chunkSize = 1000,
        int $chunkOverlap = 200,
        array $separators = RecursiveCharacterTextSplitter::DEFAULT_SEPARATORS,
    ): array {
        return $this->split(new RecursiveCharacterTextSplitter(
            chunkSize: $chunkSize,
            chunkOverlap: $chunkOverlap,
            separators: $separators,
        ));
    }

    /**
     * Split a document's text content using a custom splitter implementation.
     *
     * @return array<int, string>
     */
    public function split(Splitter $splitter): array
    {
        return $splitter->split($this->splittableContent());
    }

    /**
     * Split a document's text content using a custom splitter implementation.
     *
     * @return array<int, string>
     */
    public function splitWith(Splitter $splitter): array
    {
        return $this->split($splitter);
    }

    protected function splittableContent(): string
    {
        if (! method_exists($this, 'content')) {
            throw new RuntimeException('Unable to split text for ['.static::class.'] because content() is unavailable.');
        }

        /** @var callable(): string $resolver */
        $resolver = [$this, 'content'];

        return $resolver();
    }
}
