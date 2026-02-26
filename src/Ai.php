<?php

namespace Laravel\Ai;

use Illuminate\Support\Facades\Facade;

/**
 * @see AiManager
 */
class Ai extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return AiManager::class;
    }
}
