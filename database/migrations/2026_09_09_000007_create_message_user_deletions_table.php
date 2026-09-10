<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Delete for me" is per (message, reader), so it cannot live on the message
     * row the way deleted_at does.
     *
     * This is the fifth table, and AGENTS.md warns against exactly this shape
     * for read receipts. The difference is density: a read-receipt table gets a
     * row for every message times every reader, while this one only gets a row
     * where somebody actually hid something.
     */
    public function up(): void
    {
        Schema::create('message_user_deletions', function (Blueprint $table) {
            $table->foreignUlid('message_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['message_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_user_deletions');
    }
};
