<?php

namespace Laravel\Ai\Enums\OpenRouter;

use Laravel\Ai\Contracts\ServiceTier as ServiceTierContract;

/**
 * Service tiers supported by the OpenRouter API.
 *
 * @see https://openrouter.ai/docs/guides/features/service-tiers
 */
enum ServiceTier: string implements ServiceTierContract
{
    case Flex = 'flex';
    case Priority = 'priority';
}
