<?php

namespace Laravel\Ai\Contracts;

use Laravel\Ai\Tools\Request;

/**
 * A tool that renders as a surface.
 *
 * On its own the run continues past it, so whatever the surface can send back
 * is inert: nothing is waiting for it. Combined with Approvable, the pause that
 * would have shown a generic approve-or-reject prompt shows the surface
 * instead, and the user answers it with Decision::submit().
 */
interface Presentable
{
    /**
     * Get the surface this call renders as.
     */
    public function present(Request $request): Surface;
}
