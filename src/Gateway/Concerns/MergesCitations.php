<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\Data\UrlCitation;

trait MergesCitations
{
    /**
     * Get the citation for the given URL, adding it to the collection when it is new.
     *
     * @param  Collection<int, UrlCitation>  $citations
     */
    protected function mergeCitation(Collection $citations, string $url, ?string $title): UrlCitation
    {
        $existing = $citations->first(fn (UrlCitation $citation): bool => $citation->url === $url);

        if ($existing === null) {
            $citations->push($existing = new UrlCitation($url, $title));
        }

        return $existing;
    }
}
