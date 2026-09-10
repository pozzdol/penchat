<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 10);
            $table->string('name')->nullable();
            // The two participant ids of a direct chat, ordered and joined.
            // The unique index is what lets the database resolve two people
            // opening the same chat at once. Two ULIDs plus a separator.
            $table->string('direct_key', 53)->nullable()->unique();
            // Null on a direct chat: two equals, nobody in charge. Transferable
            // on a group, which is why it is not called `created_by`.
            $table->foreignUlid('owner_id')->nullable()->constrained('users');
            $table->boolean('admins_can_promote')->default(false);
            $table->boolean('members_can_add')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
