<?php

namespace Laravel\Ai\Contracts;

use BackedEnum;

/**
 * Marker interface implemented by every provider's service tier enum.
 *
 * Service tiers let an agent opt into a faster (higher priority) or cheaper
 * (lower priority) processing tier than the provider default. Each provider
 * exposes its own native values, so the enums live under the provider's own
 * namespace and resolve down to a plain string before reaching the gateway.
 */
interface ServiceTier extends BackedEnum
{
    //
}
