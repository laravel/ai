<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\UniqueIdentifierGenerator;
use Laravel\Ai\Migrations\AiMigration;

return new class extends AiMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $columnType = config('ai.database.id_column_type', 'string');
        $idLength = resolve(UniqueIdentifierGenerator::class)->length();

        Schema::create('agent_conversations', function (Blueprint $table) use ($columnType, $idLength) {
            $table->{$columnType}('id', $idLength)->primary();
            $table->foreignId('user_id');
            $table->string('title');
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('agent_conversation_messages', function (Blueprint $table) use ($columnType, $idLength) {
            $table->{$columnType}('id', $idLength)->primary();
            $table->{$columnType}('conversation_id', $idLength)->index();
            $table->foreignId('user_id');
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content');
            $table->text('attachments');
            $table->text('tool_calls');
            $table->text('tool_results');
            $table->text('usage');
            $table->text('meta');
            $table->timestamps();

            $table->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
            $table->index(['user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_conversations');
        Schema::dropIfExists('agent_conversation_messages');
    }
};
