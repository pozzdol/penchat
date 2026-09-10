<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    /**
     * Route-level `can:send,conversation` has already run.
     *
     * The transaction is not strictly needed for a bare message, but it is the
     * shape attachments need in Phase 3, and writing it now means that change
     * does not have to reopen this method.
     */
    public function store(StoreMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($request, $conversation, $user) {
            $message = $conversation->messages()->create([
                'user_id' => $user->id,
                'body' => $request->validated('body'),
            ]);

            // You have plainly seen what you just replied to.
            $conversation->participants()->updateExistingPivot($user->id, [
                'last_read_message_id' => $message->id,
            ]);
        });

        return back();
    }
}
