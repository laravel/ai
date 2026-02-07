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
        Schema::create('agent_conversations', function (Blueprint $table) {
            $table->string('id', 36)->primary();

            // Conditionally add tenant column
            if (config('ai.multi_tenancy.enabled')) {
                $tenantColumn = config('ai.multi_tenancy.column', 'tenant_id');
                $table->unsignedBigInteger($tenantColumn)->nullable()->index();

                // Add foreign key if configured
                if (config('ai.multi_tenancy.foreign_key_constraint') &&
                    $foreignTable = config('ai.multi_tenancy.foreign_table')) {
                    $table->foreign($tenantColumn)
                        ->references('id')
                        ->on($foreignTable)
                        ->onDelete(config('ai.multi_tenancy.on_delete', 'cascade'));
                }
            }

            $table->foreignId('user_id');
            $table->string('title');
            $table->timestamps();

            // Update index to include tenant if enabled
            if (config('ai.multi_tenancy.enabled')) {
                $tenantColumn = config('ai.multi_tenancy.column', 'tenant_id');
                $table->index([$tenantColumn, 'user_id', 'updated_at']);
            } else {
                $table->index(['user_id', 'updated_at']);
            }
        });

        Schema::create('agent_conversation_messages', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();

            // Conditionally add tenant column
            if (config('ai.multi_tenancy.enabled')) {
                $tenantColumn = config('ai.multi_tenancy.column', 'tenant_id');
                $table->unsignedBigInteger($tenantColumn)->nullable();

                // Add foreign key if configured
                if (config('ai.multi_tenancy.foreign_key_constraint') &&
                    $foreignTable = config('ai.multi_tenancy.foreign_table')) {
                    $table->foreign($tenantColumn)
                        ->references('id')
                        ->on($foreignTable)
                        ->onDelete(config('ai.multi_tenancy.on_delete', 'cascade'));
                }
            }

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

            // Update indexes to include tenant if enabled
            if (config('ai.multi_tenancy.enabled')) {
                $tenantColumn = config('ai.multi_tenancy.column', 'tenant_id');
                $table->index([$tenantColumn, 'conversation_id'], 'tenant_conversation_index'); // to enhance performance of queries that filter by tenant and conversation id
                $table->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index'); // to enhance performance of queries that filter by conversation id and user id
                $table->index([$tenantColumn, 'user_id']); // to enhance performance of queries that filter by tenant and user id
            } else {
                $table->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
                $table->index(['user_id']);
            }
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
