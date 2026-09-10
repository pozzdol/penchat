<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The second grey tick.
 *
 * A high-water pointer expresses exactly one boundary, and "it reached their
 * device" and "they opened it" are two. `last_read_message_id` could not carry
 * both, so this sits beside it with the same shape and the same rules: a real
 * message's id, never arithmetic, compared with `strcmp`.
 *
 * Still one row per participant. This is not the read-receipt table AGENTS.md
 * rules out — that one grows with messages × readers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_user', function (Blueprint $table) {
            $table->char('last_delivered_message_id', 26)
                ->nullable()
                ->after('last_read_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_user', function (Blueprint $table) {
            $table->dropColumn('last_delivered_message_id');
        });
    }
};
