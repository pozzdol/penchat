<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The handle people type to reach someone. Stored lowercase, so the unique
     * index doubles as a case-insensitive one on Postgres.
     *
     * Nullable at the database only so this migration applies to rows that
     * predate it; registration requires a username, so new accounts always
     * have one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 20)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
