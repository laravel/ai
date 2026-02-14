<?php

declare(strict_types=1);

namespace Laravel\Ai\Splitters;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Splitter;

class RecursiveCharacterTextSplitter implements Splitter
{
    /**
     * @var array<int, string>
     */
    public const DEFAULT_SEPARATORS = ["\n\n", "\n", ' ', ''];

    /**
     * @param  array<int, string>  $separators
     */
    public function __construct(
        protected readonly int $chunkSize = 1000,
        protected readonly int $chunkOverlap = 200,
        protected readonly array $separators = self::DEFAULT_SEPARATORS,
    ) {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('Chunk size must be at least 1.');
        }

        if ($chunkOverlap < 0) {
            throw new InvalidArgumentException('Chunk overlap cannot be negative.');
        }

        if ($chunkOverlap >= $chunkSize) {
            throw new InvalidArgumentException('Chunk overlap must be smaller than chunk size.');
        }

        if ($separators === []) {
            throw new InvalidArgumentException('At least one separator is required.');
        }

        foreach ($separators as $separator) {
            if (! is_string($separator)) {
                throw new InvalidArgumentException('Separators must be strings.');
            }
        }
    }

    /**
     * Split a text payload into chunks.
     *
     * @return array<int, string>
     */
    public function split(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $segments = $this->splitRecursively($text, $this->separators);

        return $this->mergeWithOverlap($segments);
    }

    /**
     * Split a text payload into chunks.
     *
     * @return array<int, string>
     */
    public function splitText(string $text): array
    {
        return $this->split($text);
    }

    /**
     * @param  array<int, string>  $separators
     * @return array<int, string>
     */
    protected function splitRecursively(string $text, array $separators): array
    {
        if ($text === '') {
            return [];
        }

        if (Str::length($text) <= $this->chunkSize) {
            return [$text];
        }

        [$separator, $nextSeparators] = $this->selectSeparator($text, $separators);

        $segments = [];

        foreach ($this->splitOnSeparator($text, $separator) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (Str::length($segment) <= $this->chunkSize) {
                $segments[] = $segment;

                continue;
            }

            if ($nextSeparators !== []) {
                $segments = [
                    ...$segments,
                    ...$this->splitRecursively($segment, $nextSeparators),
                ];

                continue;
            }

            $segments = [
                ...$segments,
                ...$this->hardWrap($segment),
            ];
        }

        return array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));
    }

    /**
     * @param  array<int, string>  $separators
     * @return array{0: string, 1: array<int, string>}
     */
    protected function selectSeparator(string $text, array $separators): array
    {
        $separator = $separators[array_key_last($separators)] ?? '';
        $separatorIndex = array_key_last($separators) ?? 0;

        foreach ($separators as $index => $candidate) {
            if ($candidate === '' || str_contains($text, $candidate)) {
                $separator = $candidate;
                $separatorIndex = $index;

                break;
            }
        }

        return [$separator, array_values(array_slice($separators, $separatorIndex + 1))];
    }

    /**
     * @return array<int, string>
     */
    protected function splitOnSeparator(string $text, string $separator): array
    {
        if ($separator === '') {
            return $this->splitByCharacter($text);
        }

        $parts = explode($separator, $text);

        if (count($parts) === 1) {
            return [$text];
        }

        $segments = [];
        $lastPartIndex = count($parts) - 1;

        foreach ($parts as $index => $part) {
            if ($part !== '') {
                $segments[] = $part;
            }

            if ($index < $lastPartIndex) {
                $segments[] = $separator;
            }
        }

        return $segments === [] ? [$text] : $segments;
    }

    /**
     * @return array<int, string>
     */
    protected function splitByCharacter(string $text): array
    {
        $segments = [];
        $length = Str::length($text);

        for ($index = 0; $index < $length; $index++) {
            $segments[] = Str::substr($text, $index, 1);
        }

        return $segments;
    }

    /**
     * @param  array<int, string>  $segments
     * @return array<int, string>
     */
    protected function mergeWithOverlap(array $segments): array
    {
        $chunks = [];
        $currentChunk = '';
        $currentHasNewContent = false;

        foreach ($segments as $segment) {
            $pending = $segment;

            while ($pending !== '') {
                $pendingLength = Str::length($pending);
                $remainingSpace = $this->chunkSize - Str::length($currentChunk);

                if ($remainingSpace <= 0) {
                    $chunks[] = $currentChunk;
                    $currentChunk = $this->overlapTail($currentChunk);
                    $currentHasNewContent = false;

                    continue;
                }

                if (
                    $pendingLength > $remainingSpace &&
                    $currentChunk !== '' &&
                    $currentHasNewContent
                ) {
                    $chunks[] = $currentChunk;
                    $currentChunk = $this->overlapTail($currentChunk);
                    $currentHasNewContent = false;

                    continue;
                }

                $slice = $pendingLength <= $remainingSpace
                    ? $pending
                    : Str::substr($pending, 0, $remainingSpace);

                if ($slice === '') {
                    break;
                }

                $currentChunk .= $slice;
                $currentHasNewContent = true;
                $pending = Str::substr($pending, Str::length($slice));

                if (Str::length($currentChunk) === $this->chunkSize) {
                    $chunks[] = $currentChunk;
                    $currentChunk = $this->overlapTail($currentChunk);
                    $currentHasNewContent = false;
                }
            }
        }

        if ($currentHasNewContent && $currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    protected function overlapTail(string $chunk): string
    {
        if ($this->chunkOverlap === 0) {
            return '';
        }

        $length = Str::length($chunk);

        if ($length <= $this->chunkOverlap) {
            return $chunk;
        }

        return Str::substr($chunk, $length - $this->chunkOverlap);
    }

    /**
     * @return array<int, string>
     */
    protected function hardWrap(string $text): array
    {
        $chunks = [];
        $length = Str::length($text);

        for ($offset = 0; $offset < $length; $offset += $this->chunkSize) {
            $chunks[] = Str::substr($text, $offset, $this->chunkSize);
        }

        return array_values(array_filter($chunks, static fn (string $chunk): bool => $chunk !== ''));
    }
}
