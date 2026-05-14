<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Concerns\HasConversations;
use Laravel\Ai\Models\Conversation;

uses(RefreshDatabase::class)->beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->artisan('migrate', [
        '--path' => __DIR__.'/../../database/migrations',
        '--realpath' => true,
    ])->run();
})->in(__FILE__);

test('model can retrieve conversations using relationship', function () {
    $user = ConversationRelationshipUser::create(['name' => 'Taylor']);
    $otherUser = ConversationRelationshipUser::create(['name' => 'Abigail']);

    DB::table('agent_conversations')->insert([
        [
            'id' => 'conversation-1',
            'user_id' => $user->id,
            'title' => 'First Conversation',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ],
        [
            'id' => 'conversation-2',
            'user_id' => $user->id,
            'title' => 'Second Conversation',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ],
        [
            'id' => 'conversation-3',
            'user_id' => $otherUser->id,
            'title' => 'Other Conversation',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $conversations = $user->conversations()->latest('updated_at')->get();

    expect($conversations)->toHaveCount(2)
        ->and($conversations->pluck('id')->all())->toEqual(['conversation-2', 'conversation-1'])
        ->and($conversations->first())->toBeInstanceOf(Conversation::class);
});

test('conversation can retrieve messages using relationship', function () {
    $user = ConversationRelationshipUser::create(['name' => 'Taylor']);

    $conversation = Conversation::create([
        'id' => 'conversation-1',
        'user_id' => $user->id,
        'title' => 'Conversation',
    ]);

    DB::table('agent_conversation_messages')->insert([
        [
            'id' => 'message-1',
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'agent' => 'Agent',
            'role' => 'user',
            'content' => 'Hello',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect($conversation->messages)->toHaveCount(1)
        ->and($conversation->messages->first()->content)->toBe('Hello')
        ->and($conversation->messages->first()->attachments)->toBeArray();
});

class ConversationRelationshipUser extends Model
{
    use HasConversations;

    protected $table = 'users';

    protected $guarded = [];
}
