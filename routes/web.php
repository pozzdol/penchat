<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ChatController;
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
    Route::get('/c/{conversation}', [ChatController::class, 'show'])
        ->name('chat.show')
        ->can('view', 'conversation');
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});
