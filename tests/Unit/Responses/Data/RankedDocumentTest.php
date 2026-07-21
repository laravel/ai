<?php

use Laravel\Ai\Responses\Data\RankedDocument;

test('ranked document stores index document and score', function (): void {
    $doc = new RankedDocument(0, 'test document content', 0.95);

    expect($doc->index)->toBe(0)
        ->and($doc->document)->toBe('test document content')
        ->and($doc->score)->toBe(0.95);
});

test('ranked document to array returns all properties', function (): void {
    $doc = new RankedDocument(2, 'relevant content', 0.85);

    $array = $doc->toArray();

    expect($array['index'])->toBe(2)
        ->and($array['document'])->toBe('relevant content')
        ->and($array['score'])->toBe(0.85);
});

test('ranked document json serialize returns to array', function (): void {
    $doc = new RankedDocument(1, 'search result', 0.75);

    $json = $doc->jsonSerialize();

    expect($json['index'])->toBe(1)
        ->and($json['document'])->toBe('search result')
        ->and($json['score'])->toBe(0.75);
});

test('ranked document to string returns document content', function (): void {
    $doc = new RankedDocument(0, 'document text content', 0.5);

    expect((string) $doc)->toBe('document text content');
});
