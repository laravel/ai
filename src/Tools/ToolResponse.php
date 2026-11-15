<?php

namespace Laravel\Ai\Tools;

use InvalidArgumentException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Stringable;

class ToolResponse implements Stringable
{
    /**
     * The files the model should see along with the text.
     *
     * @var array<int, Image|Document>
     */
    public array $attachments = [];

    /**
     * Create a new tool response instance.
     *
     * @param  iterable<int, Image|Document>  $attachments
     */
    public function __construct(public string $text = '', iterable $attachments = [])
    {
        $this->attach(...$attachments);
    }

    /**
     * Create a new tool response instance.
     *
     * @param  iterable<int, Image|Document>  $attachments
     */
    public static function make(Stringable|string $text = '', iterable $attachments = []): self
    {
        return new self((string) $text, $attachments);
    }

    /**
     * Attach files for the model to see along with the text.
     */
    public function attach(mixed ...$files): static
    {
        foreach ($files as $file) {
            if (! $file instanceof Image && ! $file instanceof Document) {
                throw new InvalidArgumentException('Tool responses can only attach images and documents, ['.get_debug_type($file).'] given.');
            }

            $this->attachments[] = $file;
        }

        return $this;
    }

    /**
     * Get the text of the response.
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
