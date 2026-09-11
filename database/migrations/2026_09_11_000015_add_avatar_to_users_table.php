<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A profile photo, as a path on the `public` disk.
 *
 * A column and not a row in `attachments`: that table belongs to messages and
 * carries a `message_id`. A person has at most one photo, it is replaced
 * rather than accumulated, and it has no thread to belong to.
 *
 * Not encrypted, unlike `messages.body`. An avatar is published to everyone
 * the person chats with by definition — encrypting the pointer to a file that
 * is served unauthenticated over HTTP would protect nothing and only make the
 * column unsearchable.
 *
 * Stores a *generated* name (`avatars/<ulid>.webp`), never anything the
 * uploader chose, and a new one on every replacement — which is also what
 * makes the URL self-busting, since no cache ever sees the same path twice
 * with different bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
