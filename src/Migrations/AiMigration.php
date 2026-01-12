<?php

namespace Laravel\Pennant\Migrations;

use Illuminate\Database\Migrations\Migration;

abstract class AiMigration extends Migration
{
    /**
     * Get the migration connection name.
     *
     * @return string
     */
    public function getConnection()
    {
        return config('database.default');
    }
}
