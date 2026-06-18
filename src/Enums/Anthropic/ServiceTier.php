<?php

namespace Laravel\Ai\Enums\Anthropic;

use Laravel\Ai\Contracts\ServiceTier as ServiceTierContract;

/**
 * Service tiers supported by the Anthropic Messages API.
 *
 * Anthropic has no explicit "priority" request value: send "auto" to
 * opportunistically use priority capacity when available, or "standard_only"
 * to always use standard capacity.
 *
 * @see https://docs.anthropic.com/en/api/service-tiers
 */
enum ServiceTier: string implements ServiceTierContract
{
    case Auto = 'auto';
    case StandardOnly = 'standard_only';
}
