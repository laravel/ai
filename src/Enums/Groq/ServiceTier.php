<?php

namespace Laravel\Ai\Enums\Groq;

use Laravel\Ai\Contracts\ServiceTier as ServiceTierContract;

/**
 * Service tiers supported by the Groq API.
 *
 * @see https://console.groq.com/docs/service-tiers
 */
enum ServiceTier: string implements ServiceTierContract
{
    case Auto = 'auto';
    case OnDemand = 'on_demand';
    case Flex = 'flex';
    case Performance = 'performance';
}
