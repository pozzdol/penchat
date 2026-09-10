<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_user', function (Blueprint $table) {
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member');

            // Two high-water marks. A message is read by this participant when
            // its id is at or below the first, and hidden from them when it is
            // at or below the second.
            //
            // Deliberately NOT foreign keys: they are values to compare
            // against, and nullOnDelete would silently rewind someone's
            // position whenever a message is removed.
            $table->char('last_read_message_id', 26)->nullable();
            $table->char('cleared_up_to_message_id', 26)->nullable();
            $table->timestamp('joined_at')->useCurrent();

            $table->primary(['conversation_id', 'user_id']);
            // Postgres does not index FK columns for you; "my conversations"
            // needs this one.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_user');
    }
};
