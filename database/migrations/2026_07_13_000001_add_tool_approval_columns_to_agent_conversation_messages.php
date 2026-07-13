<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

return new class extends AiMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        if (! Schema::hasTable($messagesTable) || Schema::hasColumn($messagesTable, 'provider_content_blocks')) {
            return;
        }

        Schema::table($messagesTable, function (Blueprint $table) {
            $table->text('provider_content_blocks')->nullable();
            $table->timestamp('resumed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        if (! Schema::hasTable($messagesTable) || ! Schema::hasColumn($messagesTable, 'provider_content_blocks')) {
            return;
        }

        Schema::table($messagesTable, function (Blueprint $table) {
            $table->dropColumn(['provider_content_blocks', 'resumed_at']);
        });
    }
};
