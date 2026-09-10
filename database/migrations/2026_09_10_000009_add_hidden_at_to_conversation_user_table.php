<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separates "empty this conversation" from "take it off my list".
 *
 * Both actions move `cleared_up_to_message_id`, so without a second marker
 * they are the same operation. `hidden_at` is what makes Delete chat different
 * from Clear history: the row disappears until somebody writes again.
 *
 * It is a marker, not a state to keep in sync — visibility is derived. A
 * participant's row is hidden while `hidden_at` is set *and* nothing has
 * arrived since they cleared. Clearing history nulls it, so the two actions
 * cannot leave each other in a contradictory state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_user', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('cleared_up_to_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_user', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });
    }
};
