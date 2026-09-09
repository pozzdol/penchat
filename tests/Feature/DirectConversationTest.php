<?php

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('creates exactly one direct conversation no matter how often or in which order', function () {
    [$a, $b] = User::factory()->count(2)->create();

    $first = Conversation::findOrCreateDirect($a, $b);
    $again = Conversation::findOrCreateDirect($a, $b);
    $swapped = Conversation::findOrCreateDirect($b, $a);

    expect($again->id)->toBe($first->id)
        ->and($swapped->id)->toBe($first->id)
        ->and($first->type)->toBe(ConversationType::Direct)
        ->and($first->direct_key)->toBe(min($a->id, $b->id).'-'.max($a->id, $b->id))
        ->and(Conversation::count())->toBe(1)
        ->and(DB::table('conversation_user')->where('conversation_id', $first->id)->count())->toBe(2);
});

it('keeps the original joined_at when called again', function () {
    [$a, $b] = User::factory()->count(2)->create();

    Conversation::findOrCreateDirect($a, $b);
    $joined = DB::table('conversation_user')->where('user_id', $a->id)->value('joined_at');

    $this->travel(1)->hour();
    Conversation::findOrCreateDirect($a, $b);

    expect(DB::table('conversation_user')->where('user_id', $a->id)->value('joined_at'))->toBe($joined);
});

it('refuses a conversation with oneself', function () {
    $a = User::factory()->create();

    Conversation::findOrCreateDirect($a, $a);
})->throws(InvalidArgumentException::class);
