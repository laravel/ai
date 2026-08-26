<?php

namespace Laravel\Ai\Contracts;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A pre-approved thing a client knows how to render.
 *
 * The model never names a surface; it fills one in. How it looks belongs to
 * the client, and everything it can send back is declared by actions() on the
 * class rather than at each call site, so a tool cannot widen the list.
 *
 * actions() describes what the surface is capable of emitting, not what is
 * accepted right now. A surface rendered by a tool that is not also Approvable
 * has no pause waiting for it, so its actions stay inert.
 *
 * @extends Arrayable<string, mixed>
 */
interface Surface extends Arrayable
{
    /**
     * Get the name the client resolves this surface by.
     */
    public static function name(): string;

    /**
     * Get the actions this surface is capable of sending back.
     *
     * @return array<int, string>
     */
    public static function actions(): array;

    /**
     * Get the surface's props.
     *
     * @return array<string, mixed>
     */
    public function toArray();
}
