<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quoting a message when you reply to it.
 *
 * Two columns, and they answer different questions. `reply_to_message_id` is
 * the link — what to scroll to when the quote is clicked, and where to read
 * the original author's name from. `reply_to_body` is a snapshot of the words
 * as they stood when reply was pressed, so an edit to the original does not
 * quietly rewrite history in someone else's message.
 *
 * The author is deliberately *not* snapshotted. A message row is never
 * removed — deleting leaves a tombstone — so the original is always there to
 * join, and a name does not change under you. Only the body is worth freezing.
 *
 * `text`, not `varchar`, and encrypted in the model: this is message content
 * at rest and it must be as unreadable in a dump as `body` is. Ciphertext runs
 * several times longer than its plaintext, which is the trap that nearly bit
 * `conversations.name`.
 *
 * No foreign key on `reply_to_message_id`. A cascade would delete a reply when
 * the message it quotes is removed, and the whole point of the snapshot is
 * that the reply stands on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->char('reply_to_message_id', 26)->nullable()->after('user_id');
            $table->text('reply_to_body')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['reply_to_message_id', 'reply_to_body']);
        });
    }
};
