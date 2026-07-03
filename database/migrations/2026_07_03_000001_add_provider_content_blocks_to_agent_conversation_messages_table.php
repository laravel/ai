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
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasColumn($messagesTable, 'provider_content_blocks')) {
            return;
        }

        $schema->table($messagesTable, function (Blueprint $table) {
            $table->text('provider_content_blocks')->nullable()->after('attachments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $messagesTable = config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $schema = Schema::connection($this->getConnection());

        if (! $schema->hasColumn($messagesTable, 'provider_content_blocks')) {
            return;
        }

        $schema->table($messagesTable, function (Blueprint $table) {
            $table->dropColumn('provider_content_blocks');
        });
    }
};
