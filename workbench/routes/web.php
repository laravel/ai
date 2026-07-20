<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
