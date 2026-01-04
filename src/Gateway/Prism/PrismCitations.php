<?php

namespace Laravel\Ai\Gateway\Prism;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\Citation;
use Laravel\Ai\Responses\Data\UrlCitation;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\ValueObjects\Citation as PrismCitation;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

class PrismCitations
{
    /**
     * Extract URL citations from Prism response additional content.
     *
     * @param  array<string, mixed>  $additionalContent
     */
    public static function toLaravelCitations(array $additionalContent): Collection
    {
        $citations = $additionalContent['citations'] ?? [];

        return collect($citations)
            ->flatMap(fn (MessagePartWithCitations $part) => $part->citations)
            ->filter(fn (PrismCitation $citation) => $citation->sourceType === CitationSourceType::Url)
            ->map(fn (PrismCitation $citation) => new UrlCitation(
                $citation->source,
                $citation->sourceTitle,
            ))
            ->unique(function (Citation $citation) {
                return $citation->title;
            })
            ->values();
    }
}
