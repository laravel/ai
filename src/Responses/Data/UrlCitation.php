<?php

namespace Laravel\Ai\Responses\Data;

class UrlCitation extends Citation
{
    public function __construct(
        public string $url,
        ?string $title = null,
    ) {
        parent::__construct($title);
    }
}
