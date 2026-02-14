<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Splitter;
use Laravel\Ai\Files\Document;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocumentSplitTest extends TestCase
{
    public function test_document_from_string_can_be_split_with_default_splitter(): void
    {
        $text = 'Alpha beta gamma delta epsilon zeta eta theta';

        $chunks = Document::fromString($text, 'text/plain')->splitText(
            chunkSize: 12,
            chunkOverlap: 2,
            separators: [' ', ''],
        );

        $this->assertNotEmpty($chunks);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(12, Str::length($chunk));
        }

        $this->assertSame($text, $this->reconstructFromOverlap($chunks, 2));
    }

    public function test_document_can_split_with_custom_splitter_implementation(): void
    {
        $document = Document::fromString('Hello world', 'text/plain');

        $splitter = new class implements Splitter
        {
            /**
             * @return array<int, string>
             */
            public function split(string $text): array
            {
                return ['custom', $text];
            }
        };

        $this->assertSame(['custom', 'Hello world'], $document->split($splitter));
    }

    public function test_provider_documents_throw_when_content_is_unavailable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('content() is unavailable');

        Document::fromId('doc_123')->splitText();
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
