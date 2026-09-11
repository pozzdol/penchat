<?php

namespace App\Http\Controllers;

use App\Events\ConversationTouched;
use App\Http\Requests\UpdateProfilePhotoRequest;
use App\Models\Conversation;
use App\Support\Avatar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Its own controller rather than two more methods on `ProfileController`:
 * a name is a string and a photo is a file, and everything that makes the
 * second one dangerous — decoding, re-encoding, deleting what it replaced —
 * has nothing to say about the first.
 */
class ProfilePhotoController extends Controller
{
    public function update(UpdateProfilePhotoRequest $request): RedirectResponse
    {
        Avatar::replace($request->user(), $request->file('photo'));

        $this->announce($request->user()->id);

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        Avatar::remove($request->user());

        $this->announce($request->user()->id);

        return back();
    }

    /**
     * A photo appears beside this person's name in every sidebar and every
     * bubble they have written, so changing it has to reach those screens —
     * the same reason a rename does, and by the same thin signal.
     */
    private function announce(string $userId): void
    {
        Conversation::query()
            ->whereHas('participants', fn ($q) => $q->whereKey($userId))
            ->get()
            ->each(fn (Conversation $c) => ConversationTouched::dispatch($c));
    }
}
