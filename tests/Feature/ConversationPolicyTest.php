<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('lets a participant view a conversation and nobody else', function () {
    [$a, $b, $outsider] = User::factory()->count(3)->create();
    $conversation = Conversation::findOrCreateDirect($a, $b);

    expect(Gate::forUser($a)->allows('view', $conversation))->toBeTrue()
        ->and(Gate::forUser($outsider)->allows('view', $conversation))->toBeFalse();
});

it('returns 403 on a conversation route for a non-participant', function () {
    [$a, $b, $outsider] = User::factory()->count(3)->create();
    $conversation = Conversation::findOrCreateDirect($a, $b);

    $this->actingAs($a)->get("/c/{$conversation->id}")->assertOk();
    $this->actingAs($outsider)->get("/c/{$conversation->id}")->assertForbidden();
});

it('sends a guest to the sign-in page instead of a 401', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $conversation = Conversation::findOrCreateDirect($a, $b);

    $this->get("/c/{$conversation->id}")->assertRedirect('/login');
});
