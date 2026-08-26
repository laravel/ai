<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\AgentUserInteraction\AgentUserInteraction;
use Laravel\Ai\Models\Conversation;
use Workbench\App\Agents\Assistant;
use Workbench\App\Models\User;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/chat', function () {
    return view('workbench::chat');
});

Route::post('/chat/message', function (Request $request) {
    $data = $request->validate([
        'message' => ['required', 'string'],
        'conversation_id' => ['nullable', 'string'],
    ]);

    $user = User::firstOrCreate(
        ['email' => 'demo@workbench.test'],
        ['name' => 'Demo User', 'password' => bcrypt(str()->random(32))],
    );

    $agent = new Assistant;

    $agent = $data['conversation_id']
        ? $agent->continue($data['conversation_id'], as: $user)
        : $agent->forUser($user);

    $response = $agent->prompt($data['message']);

    return response()->json([
        'text' => $response->text,
        'conversation_id' => $response->conversationId,
    ]);
});

Route::post('/ag-ui', function (Request $request) {
    $chat = AgentUserInteraction::chat($request);

    $user = User::firstOrCreate(
        ['email' => 'demo@workbench.test'],
        ['name' => 'Demo User', 'password' => bcrypt(str()->random(32))],
    );

    // AG-UI clients mint the thread id, so adopt it as the conversation id...
    Conversation::firstOrCreate(
        ['id' => $chat->threadId()],
        ['participant_type' => $user->getMorphClass(), 'participant_id' => $user->getKey(), 'title' => 'AG-UI chat'],
    );

    return (new Assistant)
        ->continue($chat->threadId(), as: $user)
        ->stream($chat)
        ->usingProtocol($chat->protocol());
});

Route::get('/ag-ui/{conversation}', function (Conversation $conversation) {
    $messages = $conversation->messages()->oldest('id')->get();

    return response()->json([
        'threadId' => $conversation->id,
        'messages' => AgentUserInteraction::toMessages($messages),
        'interrupts' => AgentUserInteraction::toInterrupts($messages),
    ]);
});
