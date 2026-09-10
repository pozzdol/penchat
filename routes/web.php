<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ConversationHistoryController;
use App\Http\Controllers\ConversationMemberController;
use App\Http\Controllers\ConversationReadController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PushSubscriptionController;
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

    /*
     * A suspended account is read-only. These are the routes that put
     * content in front of other people; reading, marking read, delivery acks,
     * clearing, deleting your own copy and leaving all stay open. Freezing an
     * ack would stall everyone else's ticks, and being unable to walk away
     * from a conversation is not a sanction anyone asked for.
     */
    Route::middleware('not-suspended')->group(function () {
        Route::post('/conversations/direct', [ConversationController::class, 'direct'])
            ->name('conversations.direct');
        Route::post('/conversations', [ConversationController::class, 'store'])
            ->name('conversations.store');
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store'])
            ->name('messages.store')
            ->can('send', 'conversation');
        Route::patch('/messages/{message}', [MessageController::class, 'update'])
            ->name('messages.update')
            ->can('update', 'message');
        Route::delete('/messages/{message}', [MessageController::class, 'destroy'])
            ->name('messages.destroy')
            ->can('deleteForEveryone', 'message');
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
    });
    Route::get('/c/{conversation}', [ChatController::class, 'show'])
        ->name('chat.show')
        ->can('view', 'conversation');

    // Bodyless: it acks every conversation the caller is in at once.
    Route::post('/delivered', [DeliveryController::class, 'store'])->name('delivered');
    Route::patch('/conversations/{conversation}/read', [ConversationReadController::class, 'update'])
        ->name('conversations.read')
        ->can('markRead', 'conversation');

    Route::delete('/conversations/{conversation}/membership', [ConversationMemberController::class, 'leave'])
        ->name('conversations.leave');

    // Clearing keeps the row on the list; deleting takes it away. They are
    // separate routes because they are separate promises to the user.
    Route::delete('/conversations/{conversation}/history', [ConversationHistoryController::class, 'destroy'])
        ->name('conversations.history')
        ->can('clearHistory', 'conversation');
    Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy'])
        ->name('conversations.destroy')
        ->can('deleteChat', 'conversation');

    /*
     * Managing your own devices, deliberately outside `not-suspended`. A
     * suspension is read-only: it stops you putting content in front of other
     * people, and a notification is content arriving *at* you.
     */
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])
        ->name('push.store');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])
        ->name('push.destroy');

    Route::delete('/messages/{message}/mine', [MessageController::class, 'destroyForMe'])
        ->name('messages.mine')
        ->can('deleteForMe', 'message');
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});
