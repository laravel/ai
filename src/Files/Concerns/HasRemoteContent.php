<?php

namespace Laravel\Ai\Files\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Stringable;

trait HasRemoteContent
{
    protected ?Response $response = null;

    /**
     * Get the raw representation of the file.
     */
    public function content(): string
    {
        return $this->response()->body();
    }

    /**
     * Get the displayable name of the file.
     */
    public function name(): ?string
    {
        return $this->name ?? basename(parse_url($this->url, PHP_URL_PATH));
    }

    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string
    {
        return $this->mime ?? (new Stringable($this->response()->header('Content-Type')))->before(';')->trim()->toString();
    }

    /**
     * Set the file's MIME type.
     *
     * @return $this
     */
    public function withMimeType(string $mime): static
    {
        $this->mime = $mime;

        return $this;
    }

    /**
     * Get the HTTP response for the remote file.
     */
    protected function response(): Response
    {
        return $this->response ??= Http::get($this->url);
    }

    public function __toString(): string
    {
        return $this->content();
    }
}
