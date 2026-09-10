<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConversationHistoryController extends Controller
{
    /**
     * Empty this conversation for the viewer alone. Route-level
     * `can:clearHistory` has run.
     *
     * The row stays on the list — you are still in this conversation, you have
     * just stopped carrying its past around. Delete chat is the one that takes
     * the row away, and it lives on ConversationController.
     */
    public function destroy(Request $request, Conversation $conversation): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $conversation->clearFor($user);

        return back();
    }
}
