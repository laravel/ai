<?php

namespace Laravel\Ai\Migrations;

use Illuminate\Database\Migrations\Migration;

abstract class AiMigration extends Migration
{
    /**
     * Get the migration connection name.
     *
     * @return string|null
     */
    public function getConnection(): ?string
    {
        return config('database.default');
    }
}
