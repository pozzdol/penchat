<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ConversationMemberController;
use App\Http\Controllers\ConversationReadController;
use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login/email', [LoginController::class, 'sendCode'])->name('login.email');
    Route::post('/login/code', [LoginController::class, 'verify'])->name('login.code');
    Route::post('/login/name', [LoginController::class, 'register'])->name('login.name');
    Route::post('/login/restart', [LoginController::class, 'restart'])->name('login.restart');
});

Route::middleware('auth')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/conversations/direct', [ConversationController::class, 'direct'])
        ->name('conversations.direct');
    Route::get('/c/{conversation}', [ChatController::class, 'show'])
        ->name('chat.show')
        ->can('view', 'conversation');

    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->name('messages.store')
        ->can('send', 'conversation');
    Route::patch('/conversations/{conversation}/read', [ConversationReadController::class, 'update'])
        ->name('conversations.read')
        ->can('markRead', 'conversation');

    Route::post('/conversations', [ConversationController::class, 'store'])
        ->name('conversations.store');
    Route::patch('/conversations/{conversation}', [ConversationController::class, 'update'])
        ->name('conversations.update')
        ->can('updateSettings', 'conversation');

    Route::post('/conversations/{conversation}/members', [ConversationMemberController::class, 'store'])
        ->name('members.store')
        ->can('addMember', 'conversation');
    Route::patch('/conversations/{conversation}/members/{user}', [ConversationMemberController::class, 'update'])
        ->name('members.update')
        ->can('manageAdmins', 'conversation');
    Route::delete('/conversations/{conversation}/members/{user}', [ConversationMemberController::class, 'destroy'])
        ->name('members.destroy')
        ->can('removeMember', 'conversation');
    Route::post('/conversations/{conversation}/owner/{user}', [ConversationMemberController::class, 'transfer'])
        ->name('conversations.transfer')
        ->can('transferOwnership', 'conversation');
    Route::delete('/conversations/{conversation}/membership', [ConversationMemberController::class, 'leave'])
        ->name('conversations.leave');
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});
