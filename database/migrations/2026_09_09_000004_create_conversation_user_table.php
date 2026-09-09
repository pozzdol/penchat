<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_user', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Read state is a high-water mark, not a join table: a message is read
            // by this participant when message.id <= last_read_message_id. It is
            // deliberately NOT a foreign key — nullOnDelete would reset a reader's
            // position whenever a message is removed, and restrict would block it.
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('joined_at')->useCurrent();

            $table->primary(['conversation_id', 'user_id']);
            // Postgres does not index FK columns for you; "my conversations" needs this.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_user');
    }
};
