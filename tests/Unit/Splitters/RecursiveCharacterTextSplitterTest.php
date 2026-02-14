<?php

declare(strict_types=1);

namespace Tests\Unit\Splitters;

use Illuminate\Support\Str;
use Laravel\Ai\Splitters\RecursiveCharacterTextSplitter;
use PHPUnit\Framework\TestCase;

class RecursiveCharacterTextSplitterTest extends TestCase
{
    public function test_it_splits_simple_text_by_length(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(
            chunkSize: 5,
            chunkOverlap: 0,
            separators: [''],
        );

        $chunks = $splitter->split('abcdefghijklmnopqrstuvwxyz');

        $this->assertSame(['abcde', 'fghij', 'klmno', 'pqrst', 'uvwxy', 'z'], $chunks);
    }

    public function test_it_respects_paragraph_separators(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(
            chunkSize: 20,
            chunkOverlap: 0,
            separators: ["\n\n", "\n", ' ', ''],
        );

        $text = "Paragraph one.\n\nParagraph two.\n\nParagraph three.";
        $chunks = $splitter->split($text);

        $this->assertSame([
            "Paragraph one.\n\n",
            "Paragraph two.\n\n",
            'Paragraph three.',
        ], $chunks);
    }

    public function test_it_maintains_overlap_between_chunks(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(
            chunkSize: 10,
            chunkOverlap: 3,
            separators: [''],
        );

        $chunks = $splitter->split('abcdefghijklmnopqrstuvwxyz');

        $this->assertGreaterThan(1, count($chunks));
        $this->assertChunksWithinLimit($chunks, 10);
        $this->assertOverlaps($chunks, 3);
        $this->assertSame('abcdefghijklmnopqrstuvwxyz', $this->reconstructFromOverlap($chunks, 3));
    }

    public function test_it_handles_text_smaller_than_chunk_size(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(
            chunkSize: 100,
            chunkOverlap: 0,
            separators: ["\n\n", "\n", ' ', ''],
        );

        $text = 'Short text.';
        $chunks = $splitter->split($text);

        $this->assertSame([$text], $chunks);
    }

    /**
     * @param  array<int, string>  $chunks
     */
    private function assertChunksWithinLimit(array $chunks, int $chunkSize): void
    {
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual($chunkSize, Str::length($chunk));
        }
    }

    /**
     * @param  array<int, string>  $chunks
     */
    private function assertOverlaps(array $chunks, int $chunkOverlap): void
    {
        foreach (array_keys($chunks) as $index) {
            if (! isset($chunks[$index + 1])) {
                continue;
            }

            $this->assertSame(
                Str::substr($chunks[$index], -$chunkOverlap),
                Str::substr($chunks[$index + 1], 0, $chunkOverlap),
            );
        }
    }

    /**
     * @param  array<int, string>  $chunks
     */
    private function reconstructFromOverlap(array $chunks, int $chunkOverlap): string
    {
        $reconstructed = $chunks[0] ?? '';

        foreach ($chunks as $index => $chunk) {
            if ($index === 0) {
                continue;
            }

            $reconstructed .= Str::substr($chunk, $chunkOverlap);
        }

        return $reconstructed;
    }
}
