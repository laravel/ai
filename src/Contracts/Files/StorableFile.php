<?php

namespace Laravel\Ai\Contracts\Files;

use Stringable;

interface StorableFile extends HasContent, HasMimeType, HasName, Stringable
{
    //
}
