<?php

namespace Laravel\Ai\Exceptions;

use RuntimeException;

class FileNotFoundException extends RuntimeException
{
    public static function withPath(string $path): static
    {
        return new static("File does not exist at path [$path]");
    }
}
