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
        $path = parse_url($this->url, PHP_URL_PATH);

        return $this->name ?? basename(is_string($path) ? $path : '');
    }

    /**
     * Get the file's MIME type.
     */
    public function mimeType(): ?string
    {
        return $this->mime ?? (new Stringable($this->response()->header('Content-Type')))->before(';')->trim()->toString();
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
