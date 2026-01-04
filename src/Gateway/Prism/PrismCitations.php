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
     */
    public static function toLaravelCitations(Collection $citations): Collection
    {
        return $citations
            ->flatMap(fn (MessagePartWithCitations $part) => $part->citations)
            ->map(static::toLaravelCitation(...))
            ->filter()
            ->unique(function (Citation $citation) {
                return $citation->title;
            })
            ->values();
    }

    /**
     * Convert the given Prism citation into a Laravel citation.
     */
    public static function toLaravelCitation(PrismCitation $citation): ?Citation
    {
        if ($citation->sourceType !== CitationSourceType::Url) {
            return null;
        }

        return new UrlCitation(
            $citation->source,
            $citation->sourceTitle,
        );
    }
}
